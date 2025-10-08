# 1. Base image with Python and PHP
FROM python:3.12-slim

# 2. Install PHP, cURL, and other needed utilities
RUN apt-get update && \
    apt-get install -y php-cli php-curl curl unzip && \
    rm -rf /var/lib/apt/lists/*

# 3. Set working directory
WORKDIR /app

# 4. Copy project files
COPY . /app

# 5. Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# 6. Expose port (Render sets PORT in env)
EXPOSE 5000

# 7. Set environment variable for Python
ENV PYTHONUNBUFFERED=1

# 8. Run the Flask app with Gunicorn
CMD ["gunicorn", "app:app", "-b", "0.0.0.0:5000"]
