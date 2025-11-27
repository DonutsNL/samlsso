#!/bin/bash
#
#  ------------------------------------------------------------------------
#  samlSSO
#
#  samlSSO was inspired by the initial work of Derrick Smith's
#  PhpSaml. This project's intend is to address some structural issues
#  caused by the gradual development of GLPI and the broad amount of
#  wishes expressed by the community.
#
#  Copyright (C) 2024 by Chris Gralike
#  ------------------------------------------------------------------------
#  This script is part of the samlSSO package for GLPI.

# This script requires openssl to be installed.
# in debian install it via apt install openssl first.


echo "Available entropy"
cat /proc/sys/kernel/random/entropy_avail
echo "Generate SP key and cert for SAML Signing purposes"
openssl req -x509 -newkey rsa:4096 -keyout key.pem -out cert.pem -sha256 -days 365 -nodes