# Testing TLS connections

`TlsTest` checks that TLS settings reach the driver against any server. Its two positive tests need servers that
offer TLS, and skip otherwise. They were run this way on 2026-11-13 (FE-78).

## A PostgreSQL with TLS, beside the usual server

A throwaway cluster on another port, with a test CA. Nothing touches the server on 5432. With Git for Windows' openssl
and PostgreSQL 18's tools:

```
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -keyout ca.key -out ca.pem -subj "/CN=Zana test CA"
openssl req -newkey rsa:2048 -nodes -keyout server.key -out server.csr -subj "/CN=localhost"
printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' > san.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out server.crt -days 2 -extfile san.ext
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -keyout other-ca.key -out other-ca.pem -subj "/CN=Another CA"

initdb -D data -U postgres --auth=trust -E UTF8
copy server.crt and server.key into data/, then add to data/postgresql.conf:
    port = 5433
    listen_addresses = '127.0.0.1'
    ssl = on
    ssl_cert_file = 'server.crt'
    ssl_key_file = 'server.key'
pg_ctl -D data -l server.log start
```

Then set `ZANA_TEST_PGSQL_TLS_PORT=5433`, `ZANA_TEST_PGSQL_TLS_CA` to `ca.pem` and `ZANA_TEST_TLS_OTHER_CA` to
`other-ca.pem`. Stop it with `pg_ctl -D data stop`, and delete the folder.

## MySQL

MySQL 8 and later generate their own certificates in the data folder. Set `ZANA_TEST_MYSQL_TLS_CA` to its `ca.pem`.
Its auto-generated certificate names no host, so `ssl_verify = true` against `127.0.0.1` is refused: correct
behaviour, and why the test verifies only with another CA. A production server needs a certificate for its host name.
