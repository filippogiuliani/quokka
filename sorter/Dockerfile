# Sorter: puts a category on the new emails of the family's mailboxes (see sorter.py)
FROM python:3.12-alpine
RUN adduser -D -u 1000 sorter && mkdir -p /data && chown sorter /data
RUN pip install --no-cache-dir cryptography==50.0.2 pywebpush==2.5.0
WORKDIR /app
COPY classify.py push.py secret.py sorter.py ./
USER sorter
ENV PYTHONUNBUFFERED=1 DATA=/data ACCOUNTS=/config/accounts.json
VOLUME /data
# notifications: the service Quokka talks to on the internal network (push.py)
EXPOSE 8025
# `docker run … <image> encrypt` makes an accounts.json entry with an encrypted password
ENTRYPOINT ["python", "sorter.py"]
