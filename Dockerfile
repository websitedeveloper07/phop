# 1. Base image
FROM python:3.12-slim

# 2. Install PHP CLI and PHP cURL
RUN apt-get update && \
    apt-get install -y php-cli php-curl curl && \
    rm -rf /var/lib/apt/lists/*

# 3. Set working directory
WORKDIR /app

# 4. Copy project files
COPY . /app

# 5. Install Python dependencies
RUN pip install --no-cache-dir -r requirements.txt

# 6. Make entrypoint executable
RUN chmod +x /app/entrypoint.sh

# 7. Expose port for Flask
EXPOSE 5000

# 8. Set Python unbuffered
ENV PYTHONUNBUFFERED=1

# 9. Use entrypoint to start app
ENTRYPOINT ["/app/entrypoint.sh"]
