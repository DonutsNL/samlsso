#!/bin/bash
echo "Available entropy"
cat /proc/sys/kernel/random/entropy_avail
echo "Generate SP key and cert"
openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem -sha256 -days 365 -nodes
