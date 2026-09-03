# Apache directory-listing protection

Install and activate the configuration as root:

```bash
install -m 0644 deployment/apache/disable-directory-listing.conf \
  /etc/apache2/conf-available/disable-directory-listing.conf
a2enconf disable-directory-listing
apache2ctl configtest
systemctl reload apache2
```

Expected behavior:

- A directory without an index file returns HTTP 403 instead of listing files.
- Existing applications and explicitly addressed files remain accessible.
