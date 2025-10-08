#!/bin/bash
# Get the port from environment variable, default to 5000
PORT=${PORT:-5000}

# Run Gunicorn with Flask app
exec gunicorn app:app -b 0.0.0.0:$PORT
