# 📘 High-Throughput Laravel Scaling Playbook

> **Internal Scaling & High-Concurrency Runbook for Engineering Teams**  
> Formulated from production battle-testing, 17,000 req/s load validations, and Devon Garbalosa's *Scaling Laravel* (Laracon US 2026).

---

## Table of Contents
1. [The Philosophy: Platform vs. Application](#1-the-philosophy-platform-vs-application)
2. [Database Layer Tuning & IOPS Engineering](#2-database-layer-tuning--iops-engineering)
3. [The Autoscaling Flaw & Queue Pressure Engine](#3-the-autoscaling-flaw--queue-pressure-engine)
4. [Kubernetes KEDA & AWS Auto Scaling Configuration](#4-kubernetes-keda--aws-auto-scaling-configuration)
5. [Redis Concurrency & Connection Pooling](#5-redis-concurrency--connection-pooling)
6. [Lock-Free Concurrency & Race Condition Elimination](#6-lock-free-concurrency--race-condition-elimination)
7. [Production Incident Triage Checklist](#7-production-incident-triage-checklist)

---

## 1. The Philosophy: Platform vs. Application

> *"The cloud platform layer is almost never your bottleneck; it is almost always the database and code-level synchronization."*

When systems hit throughput ceilings (e.g. 5,000+ QPS), engineering teams often attempt to solve performance issues by throwing bigger EC2 instances or adding Kubernetes pods. This often **worsens** the problem:

1. Adding more API pods increases concurrent connection storms against the primary database.
2. Unindexed background queries (e.g. `failed_jobs`, table scans on unindexed foreign keys) consume 80%+ of total DB vCPU.
3. Adding more queue workers increases database lock contention on shared rows.

**The Golden Rule**: Optimize database indexing, eliminate lock contention, decouple read queries with sticky read replicas, and scale queues based on **wait-time pressure**, not CPU.

---

## 2. Database Layer Tuning & IOPS Engineering

### 2.1 Disable Prepared Statement Emulation
By default, PHP PDO emulates prepared statements client-side as strings. In high-concurrency environments, this bypasses MySQL/Vitess query plan caching and binary protocol multiplexing.

```php
// config/database.php
'options' => [
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 3, // strict 3-second timeout to prevent thread pool exhaustion
    PDO::ATTR_PERSISTENT => true, // connection pooling under FPM/Octane
],
```

### 2.2 Split Read/Write Connections with Sticky Safety
Separate write transactions from read queries while guaranteeing that a user immediately sees their own writes within the same lifecycle:

```php
'mysql' => [
    'driver' => 'mysql',
    'read' => [
        'host' => explode(',', env('DB_READ_HOST', '127.0.0.1')),
    ],
    'write' => [
        'host' => [env('DB_WRITE_HOST', '127.0.0.1')],
    ],
    'sticky' => true, // CRITICAL: Routes subsequent reads to the primary connection within the same request lifecycle
],
```

### 2.3 Framework-Level Table Indexes
Framework tables that handle high turnover (`failed_jobs`, `jobs`, `job_batches`) must have compound indexes:

```sql
-- Compound index on queue and failure timestamp
ALTER TABLE failed_jobs ADD INDEX failed_jobs_queue_failed_at_index (queue, failed_at);
ALTER TABLE failed_jobs ADD INDEX failed_jobs_failed_at_index (failed_at);

-- Compound index on queue and reservation states for database queues
ALTER TABLE jobs ADD INDEX jobs_queue_reserved_at_index (queue, reserved_at);
ALTER TABLE jobs ADD INDEX jobs_queue_available_at_index (queue, available_at);
```

---

## 3. The Autoscaling Flaw & Queue Pressure Engine

### 3.1 Why Host CPU Autoscaling Fails
When worker pods execute I/O-bound jobs (e.g., calling Stripe, sending Webhooks, generating S3 presigned URLs, waiting on DB locks):
* The worker threads spend **90%+ of their execution time blocked in Linux kernel `epoll_wait` / socket sleep**.
* The host node reports **CPU utilization of 3% - 8%**.
* Traditional HPA rules (e.g. `targetCPUUtilizationPercentage: 70%`) see low CPU and conclude the cluster is idle.
* Meanwhile, 500,000 jobs pile up in Redis, and end-user notification delays grow from seconds to hours.

### 3.2 The Queue Pressure Formula
Queue Pressure continuously monitors both backlog depth and processing latency:

$$\text{Queue Pressure Score} = \left(0.65 \times \frac{\text{Wait Time (seconds)}}{\text{SLA Target (e.g. 15s)}}\right) + \left(0.35 \times \frac{\text{Backlog Count}}{\text{Target Backlog Per Worker (e.g. 50)}}\right)$$

* **Score < 60**: Healthy cluster.
* **Score 60 - 100**: Moderate load.
* **Score >= 100**: SLA breached; initiate proactive scale-up.

---

## 4. Kubernetes KEDA & AWS Auto Scaling Configuration

### 4.1 KEDA ScaledObject (Kubernetes)
Deploy KEDA to query the `/api/scaling/evaluate` webhook or Redis queue depth directly:

```yaml
apiVersion: keda.sh/v1alpha1
kind: ScaledObject
metadata:
  name: laravel-queue-worker-scaler
  namespace: production
spec:
  scaleTargetRef:
    name: laravel-worker-deployment
  minReplicaCount: 4
  maxReplicaCount: 50
  cooldownPeriod: 120
  pollingInterval: 5
  triggers:
    - type: external-push
      metadata:
        scalerAddress: http://laravel-app-service.production.svc.cluster.local:80/api/scaling/evaluate
        metricName: queue_pressure
        threshold: "100"
```

### 4.2 AWS CloudWatch Alarm for AWS ECS / Auto Scaling
Create a target tracking scaling policy based on the `wait_time_seconds` metric emitted by `php artisan scaling:emit-queue-pressure`:

```json
{
  "TargetTrackingScalingPolicyConfiguration": {
    "TargetValue": 15.0,
    "CustomizedMetricSpecification": {
      "MetricName": "wait_time_seconds",
      "Namespace": "Laravel/HighThroughput",
      "Dimensions": [
        { "Name": "queue", "Value": "default" }
      ],
      "Statistic": "Maximum",
      "Unit": "Seconds"
    },
    "ScaleOutCooldown": 30,
    "ScaleInCooldown": 300
  }
}
```

---

## 5. Redis Concurrency & Connection Pooling

### 5.1 Decorrelated Jitter Backoff
When hundreds of worker pods fail or retry simultaneously (the *thundering herd* problem), standard exponential backoff causes synchronized lock collisions. Always configure decorrelated jitter:

```php
// config/database.php
'redis' => [
    'default' => [
        'max_retries' => 3,
        'backoff_algorithm' => 'decorrelated_jitter',
        'backoff_base' => 100, // ms
        'backoff_cap' => 1000, // ms
    ],
],
```

### 5.2 Redis Pipeline Batching
Never execute Redis commands in a tight loop across network sockets. Group operations into pipelines:

```php
// ❌ Anti-pattern (1,000 separate TCP roundtrips = ~500ms latency)
foreach ($events as $event) {
    Redis::rpush("events:{$event->id}", json_encode($event));
}

//  High-Throughput Pattern (1 TCP roundtrip = ~2ms latency)
Redis::pipeline(function ($pipe) use ($events) {
    foreach ($events as $event) {
        $pipe->rpush("events:{$event->id}", json_encode($event));
    }
});
```

---

## 6. Lock-Free Concurrency & Race Condition Elimination

### 6.1 Avoid Long-Lived Database Transactions
Holding a database transaction while calling an external API is the #1 cause of database connection exhaustion:

```php
// ❌ FATAL ANTI-PATTERN: Holds DB row lock during 800ms Stripe API call!
DB::transaction(function () use ($order) {
    $order->status = 'processing';
    $order->save();

    $stripeCharge = Stripe::charges()->create([...]); // 800ms HTTP latency!
    $order->status = 'paid';
    $order->save();
});

//  HIGH-THROUGHPUT PATTERN: Atomic lock + Outbox Pattern
Cache::lock("order:{$order->id}:charge", 10)->block(3, function () use ($order) {
    // 1. Check idempotency state
    if ($order->fresh()->isPaid()) {
        return;
    }

    // 2. Perform external network I/O outside DB transaction
    $charge = Stripe::charges()->create([...]);

    // 3. Fast, sub-millisecond atomic DB write
    DB::table('orders')->where('id', $order->id)->update([
        'status' => 'paid',
        'charge_id' => $charge->id,
        'updated_at' => now(),
    ]);
});
```

---

## 7. Production Incident Triage Checklist

| Symptom | Probable Cause | Immediate Action |
|---|---|---|
| **DB CPU at 100%, slow queries on `failed_jobs`** | Missing compound indexes during pruning or status check scans. | Run `2026_09_10_000001_add_performance_indexes_to_failed_jobs_table.php` migration. |
| **Worker count static while queue backlog grows to 100k+** | HPA scaling on CPU instead of Queue Pressure / Wait Time. | Switch HPA trigger to `/api/scaling/evaluate` or `wait_time_seconds` CloudWatch metric. |
| **MySQL `Too many connections` (Error 1040)** | Worker pods holding long DB transactions during network I/O. | Audit jobs for `DB::transaction()` wrapping HTTP requests; reduce `PDO::ATTR_TIMEOUT` to 3s. |
| **Replication lag causing stale read errors** | Non-sticky read replica routing. | Ensure `'sticky' => true` is enabled in `config/database.php`. |
