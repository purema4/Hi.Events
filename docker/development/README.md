# Hi.Events local development with Docker

This guide walks you through setting up Hi.Events using Docker, including requirements, setup steps, configuration,
and environment variables.

## Requirements

1. **Docker** – Required for containerized development. [Install Docker](https://docs.docker.com/get-docker/)
2. **Git** – Needed to clone the repository. [Install Git](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git)

> **Note:** This guide assumes a macOS or Linux environment by default. See the **Windows setup instructions** below if you're on Windows.

---

## Setup instructions (macOS / Linux)

### 1. Clone the repository

```bash
git clone git@github.com:HiEventsDev/Hi.Events.git
```

### 2. Start the development environment

Navigate to the Docker development directory and run the startup script:

```bash
cd Hi.Events/docker/development
./start-dev.sh
```

Once running, access the app at:

- **Frontend**: [https://localhost:8443](https://localhost:8443)

---

## Setup instructions (Windows)

Windows users should follow the steps below to manually run the setup commands instead of using the `start-dev.sh` script.

### 1. Clone the repository

Using Git Bash or Windows Terminal:

```bash
git clone git@github.com:HiEventsDev/Hi.Events.git
cd Hi.Events/docker/development
```

### 2. Generate SSL certificates

You can use `openssl` to generate self-signed certificates:

```bash
mkdir -p certs
openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout certs/localhost.pem.key -out certs/localhost.pem -subj "/CN=localhost"
```

Then, update your `docker/development/nginx/nginx.conf` to use `.pem` files:

```nginx
ssl_certificate /etc/nginx/certs/localhost.pem;
ssl_certificate_key /etc/nginx/certs/localhost.pem.key;
```

> If you're using `.crt`/`.key`, update accordingly.

### 3. Start Docker services

```bash
docker-compose -f docker-compose.dev.yml up -d
```

### 4. Install backend dependencies

```bash
docker-compose -f docker-compose.dev.yml exec -T backend composer install --ignore-platform-reqs --no-interaction --optimize-autoloader --prefer-dist
```

### 5. Wait for the database

Keep checking logs until you see "ready to accept connections":

```bash
docker-compose -f docker-compose.dev.yml logs pgsql
```

### 6. Create environment files (if missing)

```bash
docker-compose -f docker-compose.dev.yml exec backend cp .env.example .env
docker-compose -f docker-compose.dev.yml exec frontend cp .env.example .env
```

### 7. Laravel setup

```bash
docker-compose -f docker-compose.dev.yml exec backend php artisan key:generate
docker-compose -f docker-compose.dev.yml exec backend php artisan migrate
docker-compose -f docker-compose.dev.yml exec backend chmod -R 775 /var/www/html/vendor/ezyang/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer
docker-compose -f docker-compose.dev.yml exec backend php artisan storage:link
```

### 8. Open the app

```bash
start https://localhost:8443/auth/register
```

---

## Public HTTPS with ngrok

`docker-compose.ngrok.yml` serves the running development stack on your ngrok static domain, for anything that needs a
real HTTPS origin: Apple Pay / Google Pay buttons, Stripe webhooks, wallet passes and testing from a phone. It is an
overlay on `docker-compose.dev.yml` — same containers, same mounted source, so hot reload still works.

Fill in `NGROK_DOMAIN` and `NGROK_AUTHTOKEN` in `.env` (the authtoken comes from
https://dashboard.ngrok.com/get-started/your-authtoken), then:

```bash
./dev-ngrok.sh up -d           # start (or reconfigure) the stack with the tunnel
./dev-ngrok.sh logs -f ngrok
./dev-ngrok.sh down            # stop everything
```

| What | Where |
|------|-------|
| App | `https://<NGROK_DOMAIN>` |
| ngrok inspector | http://localhost:4040 |

While the overlay is up, the frontend and backend are configured with the ngrok URL, and nginx serves plain HTTP on
port 80 for the tunnel to terminate TLS in front of — `https://localhost:8443` is not served in this mode. Run
`./start-dev.sh` to go back to the normal local-only stack.

A free ngrok account allows one agent at a time, so stop the staging tunnel (`docker/staging`) before starting this one.

### Stripe wallet buttons

Apple Pay and Google Pay only render on a domain registered with Stripe. With the tunnel up:

```bash
./dev-ngrok.sh exec backend php artisan stripe:register-payment-method-domains
```

## Additional configuration

Hi.Events uses environment variables for configuration. You’ll find `.env` files in:

- `frontend/.env`
- `backend/.env`

You can modify these to customize your setup.

For a full list of environment variables, see the [Environment Variables Documentation](https://hi.events/docs/getting-started/deploying#environment-variables).
