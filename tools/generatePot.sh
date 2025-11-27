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

# This script requires gettext to be installed.
# in debian install it via apt install gettext first.

dirplugin="../"

# 1. Extract the version dynamically from setup.php
VERSION=$(grep "define('PLUGIN_SAMLSSO_VERSION'" ${dirplugin}/setup.php | awk -F "'" '{print $4}')


pathGLPIenUSpo="../../locales/"
if [ ! -d "$pathGLPIenUSpo" ];then
    mkdir -p "${pathGLPIenUSpo}"
fi

curl https://raw.githubusercontent.com/glpi-project/glpi/refs/heads/main/locales/en_US.po > "${pathGLPIenUSpo}en_US.po" 

cd $dirplugin

find ./ -type f -name "*.php" | xgettext -f - -o "locales/samlSSO.pot" -L PHP \
    --package-name="samlSSO" \
    --package-version="${VERSION}" \
    --copyright-holder="Chris Gralike" \
    --msgid-bugs-address="https://github.com/DonutsNL/samlSSO/issues" \
    --exclude-file="${pathGLPIenUSpo}en_US.po" \
    --from-code=UTF-8 \
    --force-po \
    --keyword=__
