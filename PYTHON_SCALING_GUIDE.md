# 🐍 Ultra-High-Throughput Scaling in Python: The Universal Playbook

> **Companion Guide to the Laravel High-Throughput Blueprint**  
> How to implement Queue Pressure telemetry, composite indexing, connection pooling, and autoscaling across Python stacks (**FastAPI**, **Celery**, **RQ**, **SQLAlchemy**).

---

## 🗺️ Architectural Mapping: Laravel vs. Python

| Architectural Domain | Laravel 13 Blueprint | Python Equivalent |
|---|---|---|
| **High-Concurrency Web Tier** | Laravel Octane (FrankenPHP / Swoole) | **FastAPI + Uvicorn** (`uvloop` + `httptools`) |
| **Queue & Worker Engine** | Laravel Queue / Redis Horizon | **Celery** or **RQ** / **ARQ** with Redis |
| **Database ORM & Pooling** | Eloquent + PDO (`ATTR_EMULATE_PREPARES => false`) | **SQLAlchemy 2.0 (AsyncEngine)** + `asyncmy` / `psycopg3` |
| **Migrations & Composite Indexing**| Laravel Schema Migrations | **Alembic** |
| **Queue Pressure Telemetry** | `QueuePressureMonitor.php` | `queue_pressure.py` (Redis + Celery inspector) |
| **Autoscaling Webhook** | `ScalingWebhookController.php` | FastAPI Router (`/api/scaling/evaluate`) |

---

## 1. Queue Pressure Engine in Python (Celery / Redis)

Traditional Kubernetes HPA based on CPU fails for Python Celery workers for the exact same reason as PHP: **workers blocked on socket I/O (Stripe, HTTP webhooks, LLM APIs) report 3-8% CPU while queues back up to millions**.

### Complete Python Queue Pressure Monitor (`queue_pressure.py`)

```python
import json
import time
import math
from typing import Dict, Any, Optional
import redis

class QueuePressureMonitor:
    def __init__(
        self,
        redis_client: redis.Redis,
        target_wait_time_seconds: float = 15.0,
        target_backlog_per_worker: int = 50,
        min_workers: int = 2,
        max_workers: int = 50,
    ):
        self.redis = redis_client
        self.target_wait_time = target_wait_time_seconds
        self.target_backlog = target_backlog_per_worker
        self.min_workers = min_workers
        self.max_workers = max_workers

    def get_queue_size(self, queue_name: str = "celery") -> int:
        """Inspect Redis list length for active Celery queue."""
        try:
            return self.redis.llen(queue_name)
        except Exception:
            return 0

    def get_wait_time_seconds(self, queue_name: str = "celery") -> float:
        """
        Calculate wait time by inspecting the timestamp of the oldest unhandled message
        at the head of the Redis list.
        """
        try:
            # LINDEX -1 reads the oldest item waiting to be popped (FIFO queue)
            oldest_raw = self.redis.lindex(queue_name, -1)
            if not oldest_raw:
                return 0.0

            message = json.loads(oldest_raw)
            # Celery message headers include timestamp or eta
            headers = message.get("headers", {})
            timestamp_str = headers.get("eta") or headers.get("published")

            current_time = time.time()
            if isinstance(timestamp_str, (int, float)):
                return max(0.0, round(current_time - timestamp_str, 2))
            
            # Fallback for custom payload schemas
            pushed_at = message.get("pushed_at", current_time)
            return max(0.0, round(current_time - float(pushed_at), 2))
        except Exception:
            return 0.0

    def evaluate_pressure(self, queue_name: str = "celery") -> Dict[str, Any]:
        """Compute composite Queue Pressure Score (0 to 100+)."""
        size = self.get_queue_size(queue_name)
        wait_time = self.get_wait_time_seconds(queue_name)

        normalized_wait = (wait_time / max(1.0, self.target_wait_time)) * 100.0
        normalized_backlog = (size / max(1.0, self.target_backlog)) * 100.0

        pressure_score = round((0.65 * normalized_wait) + (0.35 * normalized_backlog), 2)

        if pressure_score >= 150.0:
            status = "CRITICAL_CONGESTION"
        elif pressure_score >= 100.0:
            status = "HIGH_PRESSURE"
        elif pressure_score >= 60.0:
            status = "MODERATE_LOAD"
        else:
            status = "HEALTHY"

        return {
            "queue": queue_name,
            "size": size,
            "wait_time_seconds": wait_time,
            "pressure_score": pressure_score,
            "status": status,
        }

    def compute_autoscaling_plan(
        self, current_workers: int = 4, queue_name: str = "celery"
    ) -> Dict[str, Any]:
        """Calculate desired worker replicas for KEDA or custom orchestrators."""
        metrics = self.evaluate_pressure(queue_name)
        desired_workers = current_workers
        scaling_action = "STABLE"
        reason = "Queue pressure within SLA"

        if metrics["pressure_score"] >= 100.0:
            calculated = math.ceil(metrics["size"] / max(1.0, self.target_backlog))
            if metrics["wait_time_seconds"] > self.target_wait_time:
                calculated = math.ceil(calculated * 1.5)  # Emergency scale multiplier

            desired_workers = min(self.max_workers, max(current_workers + 1, calculated))
            scaling_action = "SCALE_UP"
            reason = (
                f"Pressure score {metrics['pressure_score']} exceeds threshold 100.0 "
                f"(Wait: {metrics['wait_time_seconds']}s, Backlog: {metrics['size']})"
            )
        elif metrics["pressure_score"] <= 30.0 and current_workers > self.min_workers:
            desired_workers = max(self.min_workers, math.floor(current_workers * 0.75))
            scaling_action = "SCALE_DOWN"
            reason = f"Pressure score {metrics['pressure_score']} is below cooldown threshold 30.0"

        return {
            "current_workers": current_workers,
            "desired_workers": desired_workers,
            "scaling_action": scaling_action,
            "reason": reason,
            "pressure_metrics": metrics,
        }
```

---

## 2. FastAPI Autoscaling Webhook (`api.py`)

Deploy a lightweight FastAPI endpoint to integrate with Kubernetes KEDA or AWS Auto Scaling:

```python
from fastapi import FastAPI, Query
from fastapi.responses import PlainTextResponse
from pydantic import BaseModel
import redis
from prometheus_client import Gauge, generate_latest, CONTENT_TYPE_LATEST
from queue_pressure import QueuePressureMonitor

app = FastAPI(title="Python High-Throughput Scaling API")

# Connect to high-performance Redis connection pool
redis_pool = redis.ConnectionPool(
    host="127.0.0.1", port=6379, db=0, max_connections=50, socket_timeout=3
)
r = redis.Redis(connection_pool=redis_pool)
monitor = QueuePressureMonitor(redis_client=r)

# Prometheus Metrics
GAUGE_QUEUE_SIZE = Gauge("celery_queue_size", "Pending jobs in queue", ["queue"])
GAUGE_WAIT_TIME = Gauge("celery_wait_time_seconds", "Delay of oldest unhandled job", ["queue"])
GAUGE_PRESSURE = Gauge("celery_queue_pressure", "Composite pressure score", ["queue"])

class ScaleRequest(BaseModel):
    queue: str = "celery"
    current_workers: int = 4

@app.post("/api/scaling/evaluate")
def evaluate_scaling(payload: ScaleRequest):
    """Webhook queried by KEDA External Scaler or AWS Lambda."""
    plan = monitor.compute_autoscaling_plan(
        current_workers=payload.current_workers, queue_name=payload.queue
    )
    return {"status": "success", "scaling": plan}

@app.get("/metrics", response_class=PlainTextResponse)
def prometheus_metrics():
    """Prometheus OpenMetrics scrape endpoint."""
    for queue_name in ["celery", "high_priority", "webhooks"]:
        metrics = monitor.evaluate_pressure(queue_name)
        GAUGE_QUEUE_SIZE.labels(queue=queue_name).set(metrics["size"])
        GAUGE_WAIT_TIME.labels(queue=queue_name).set(metrics["wait_time_seconds"])
        GAUGE_PRESSURE.labels(queue=queue_name).set(metrics["pressure_score"])

    return PlainTextResponse(generate_latest(), media_type=CONTENT_TYPE_LATEST)
```

---

## 3. Database Connection Pooling in Python (SQLAlchemy)

### 3.1 Async Engine with Connection Pooling & Statement Pre-ping
Under high concurrency, Python async workers must recycle stale connections and avoid thread deadlocks:

```python
from sqlalchemy.ext.asyncio import create_async_engine, async_sessionmaker
import os

# High-concurrency Async Engine (AsyncMy for MySQL / PlanetScale or AsyncPG for PostgreSQL)
DATABASE_URL = os.getenv(
    "DATABASE_URL", "mysql+asyncmy://root:password@127.0.0.1:3306/production_db"
)

engine = create_async_engine(
    DATABASE_URL,
    pool_size=20,          # Base connection pool per container
    max_overflow=10,       # Burst headroom for traffic spikes
    pool_recycle=300,      # Prevent MySQL 8h wait_timeout disconnects
    pool_pre_ping=True,    # Test socket before query (eliminates "MySQL server has gone away")
    connect_args={
        "connect_timeout": 3,
    },
)

AsyncSessionLocal = async_sessionmaker(bind=engine, expire_on_commit=False)
```

### 3.2 Splitting Read/Write Engines in Python
```python
# Separate Write Primary from Read Replicas
writer_engine = create_async_engine("mysql+asyncmy://user:pass@primary-db.internal/app", pool_size=15)
reader_engine = create_async_engine("mysql+asyncmy://user:pass@replica-db.internal/app", pool_size=30)
```

---

## 4. Alembic Migration: Composite Indexing (The SportMonks Win in Python)

Just as in Laravel, celery task result tables and unindexed state check tables cause 70%+ database CPU exhaustion if queried by timestamp without composite indexes.

```python
# alembic/versions/xxxx_add_performance_indexes.py
from alembic import op
import sqlalchemy as sa

def upgrade():
    # Composite index for Celery Task Results / Failed Tasks
    op.create_index(
        "ix_celery_taskmeta_queue_date_done",
        "celery_taskmeta",
        ["queue", "date_done"],
        unique=False,
    )
    # Standalone pruning index
    op.create_index(
        "ix_celery_taskmeta_date_done",
        "celery_taskmeta",
        ["date_done"],
        unique=False,
    )

def downgrade():
    op.drop_index("ix_celery_taskmeta_queue_date_done", table_name="celery_taskmeta")
    op.drop_index("ix_celery_taskmeta_date_done", table_name="celery_taskmeta")
```

---

## 5. Celery Concurrency for I/O-Bound Workloads

When tasks spend 90% of time waiting on HTTP APIs or external databases, standard prefork worker pools (`-P prefork -c 8`) waste memory. Use **Gevent / Eventlet** greenlets to run hundreds of concurrent I/O tasks per worker container:

```bash
# High-concurrency I/O worker running 100 concurrent greenlet threads:
celery -A tasks worker --pool=gevent --concurrency=100 -l info -Q webhooks,api_sync
```

---

## 6. Summary: Key Rules for High-Throughput Python

1. **Scale on Queue Pressure, Not CPU**: Deploy the `QueuePressureMonitor` and wire KEDA to `/api/scaling/evaluate`.
2. **Use Gevent for I/O Tasks**: Use `--pool=gevent` with high concurrency (50-200) for network-heavy Celery tasks.
3. **Pre-ping & Pool Connections**: Always enable `pool_pre_ping=True` and `pool_recycle=300` in SQLAlchemy.
4. **Composite Index Pruning Queries**: Never run queries like `WHERE queue = 'webhooks' AND status = 'failed' AND created_at >= ?` without a matching composite index.
