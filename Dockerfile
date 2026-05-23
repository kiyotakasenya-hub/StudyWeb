FROM php:8.2-cli

# Install SQLite tools and PHP database extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Set the working directory inside the container
WORKDIR /usr/src/app

# Copy your code into the container
COPY . .

# Run PHP's built-in server using the dynamic port Render assigns
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000}"]
