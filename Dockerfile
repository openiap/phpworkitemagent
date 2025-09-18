
FROM php:latest
LABEL anonymous="true"
LABEL non_web="true"
LABEL idle_timeout="-1"
LABEL name="Php Workitem Agent"
LABEL description="PHP serverless workitem agent"
COPY . /app
WORKDIR /app
RUN composer install --no-dev --optimize-autoloader
ENTRYPOINT ["php", "/app/main.php"]
