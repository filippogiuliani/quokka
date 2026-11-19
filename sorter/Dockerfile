# Sorter: puts a category on the new emails of the family's mailboxes (see sorter.py)
FROM python:3.12-alpine
RUN adduser -D -u 1000 sorter && mkdir -p /data && chown sorter /data
RUN pip install --no-cache-dir cryptography==44.0.3
WORKDIR /app
COPY classify.py secret.py sorter.py ./
USER sorter
ENV PYTHONUNBUFFERED=1 DATA=/data ACCOUNTS=/config/accounts.json
VOLUME /data
# `docker run … <image> encrypt` makes an accounts.json entry with an encrypted password
ENTRYPOINT ["python", "sorter.py"]
