# Read automatically by the `flask` CLI, so `flask run` also uses 5001
# (its own default is 5000, which Docker holds on this Mac).
FLASK_APP=app.py
FLASK_RUN_PORT=5001
FLASK_RUN_HOST=127.0.0.1
FLASK_DEBUG=1
