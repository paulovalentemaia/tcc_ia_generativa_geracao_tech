FROM php:8.3-cli-bookworm

# Dependências do sistema + extensões PHP + Python (3.11 no Debian bookworm)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev \
        python3 python3-venv python3-pip \
    && docker-php-ext-install pdo_mysql zip intl bcmath \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Indra OCR em um venv próprio
RUN python3 -m venv /opt/indra \
    && /opt/indra/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/indra/bin/pip install --no-cache-dir indra-ocr

# Usuário com o mesmo UID/GID do WSL (evita arquivos do projeto pertencendo ao root)
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app \
    && useradd -u ${UID} -g ${GID} -m app \
    && mkdir -p /opt/hf-cache \
    && chown -R app:app /opt/hf-cache /opt/indra

# Cache dos modelos do Indra (baixados do Hugging Face na 1ª execução)
ENV HF_HOME=/opt/hf-cache

USER app
WORKDIR /var/www

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
