"""Test bootstrap for wordpress-jumbo-mcp.

Sets a minimal single-site env BEFORE `server` is imported (server reads
WP_SITE_* at import time and sys.exit()s if none are configured), and puts the
repo root on sys.path so `import server` works from tests/.

No live WordPress is ever contacted — tests inject an httpx MockTransport.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

os.environ.setdefault("WP_SITE_QA_URL", "https://qa.example.test")
os.environ.setdefault("WP_SITE_QA_USERNAME", "qa-user")
os.environ.setdefault("WP_SITE_QA_PASSWORD", "test-not-a-secret")
os.environ.setdefault("WP_DEFAULT_SITE", "qa")
os.environ.setdefault("WP_WRITABLE_META_KEYS", "event_registration_status")
os.environ.setdefault("WP_SITE_LABEL", "")  # disable the [WP site: …] desc prefix in tests
