# 1. Base image with Python and PHP
FROM python:3.12-slim

# Install PHP and other needed utilities
RUN apt-get update && \
    apt-get install -y php-cli && \
    rm -rf /var/lib/apt/lists/*

# 2. Set working directory
WORKDIR /app

# 3. Copy project files
COPY . /app

# 4. Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# 5. Expose port (Render sets PORT in env)
EXPOSE 5000

# 6. Set environment variable for Python
ENV PYTHONUNBUFFERED=1

# 7. Run the Flask app with Gunicorn
CMD ["gunicorn", "app:app", "-b", "0.0.0.0:5000"]
