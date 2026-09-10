#!/bin/sh
# Plugin Check severity summary.
wp --path=/var/www/html --allow-root plugin check insightistic > /tmp/pc.txt 2>&1
echo "---- counts ----"
echo "ERROR rows:   $(grep -cE '(^|[[:space:]])ERROR([[:space:]]|$)' /tmp/pc.txt)"
echo "WARNING rows: $(grep -cE '(^|[[:space:]])WARNING([[:space:]]|$)' /tmp/pc.txt)"
echo "---- any errors ----"
grep -E '(^|[[:space:]])ERROR([[:space:]]|$)' /tmp/pc.txt || echo "(none)"
echo "---- error context (with file) ----"
awk '/^FILE:/{f=$0} /OffloadedContent/{print f; print}' /tmp/pc.txt || true
echo "---- distinct warning codes ----"
grep -E 'WARNING[[:space:]]+[A-Za-z0-9._-]+' -o /tmp/pc.txt | awk '{print $2}' | sort | uniq -c | sort -rn

