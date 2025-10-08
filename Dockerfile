# 1. Base image with Python and PHP
FROM python:3.12-slim

# 2. Install PHP CLI and utilities
RUN apt-get update && \
    apt-get install -y php-cli curl unzip && \
    rm -rf /var/lib/apt/lists/*

# 3. Set working directory
WORKDIR /app

# 4. Copy project files
COPY . /app

# 5. Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# 6. Expose port (Render will assign $PORT)
EXPOSE 5000

# 7. Set environment variable for Python buffering
ENV PYTHONUNBUFFERED=1

# 8. Use an entrypoint script to dynamically use $PORT
COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

# 9. Run the Flask app with Gunicorn via entrypoint
ENTRYPOINT ["/app/entrypoint.sh"]
