#!/bin/bash
# entrypoint.sh

# Make sure PHP CLI is available
which php > /dev/null 2>&1
if [ $? -ne 0 ]; then
  echo "PHP CLI not found!"
  exit 1
fi

# Make sure Gunicorn is installed
which gunicorn > /dev/null 2>&1
if [ $? -ne 0 ]; then
  echo "Gunicorn not found! Installing..."
  pip install gunicorn
fi

# Run the Flask app via Gunicorn
exec gunicorn app:app -b 0.0.0.0:5000
