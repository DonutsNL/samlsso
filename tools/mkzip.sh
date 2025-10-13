#!/bin/bash
OLDVERSION='1.2.0'
NEWVERSION='1.2.1'

# Figure out what the GLPIpath is.
FULLPATH=$(readlink -f "$0")
KNOWN_SUFFIX="/samlsso/tools/mkzip.sh"
GLPIPATH="${FULLPATH%$KNOWN_SUFFIX}"

# Verify GLPIPATH points to an directory.
if [ -d "$GLPIPATH" ]; then
	# Update the versions in headers and files
	sed -i 's/'$OLDVERSION'/'$NEWVERSION'/g' $GLPIPATH/samlsso/*.php
	sed -i 's/*  @version    '$OLDVERSION'/*  @version    '$NEWVERSION'/g' $GLPIPATH/samlsso/src/*.php
	sed -i 's/*  @version    '$OLDVERSION'/*  @version    '$NEWVERSION'/g' $GLPIPATH/samlsso/src/Config/*.php
	sed -i 's/*  @version    '$OLDVERSION'/*  @version    '$NEWVERSION'/g' $GLPIPATH/samlsso/src/LoginFlow/*.php

	# Remove old zipfiles
	if [ -f "$GLPIPATH/plugins/samlsso.zip" ]; then
		rm -y $GLPIPATH/plugins/samlsso/release/samlsso.zip
	fi
	
	cd $GLPIPATH;
	if [ -d './samlsso' ]; then 
		zip -r ./samlsso/release/samlsso.zip ./samlsso -x "/samlsso/tools/*" "/samlsso/samlsso.xml" "/samlsso/.vscode/*" "/samlsso/.gitignore" "/samlsso/.github/*" "/samlsso/.git/*" "/samlsso/release/*" "/samlsso/composer.lock" "/samlsso/vendor/bin/*" "/samlsso/vendor/myclabs/*" "/samlsso/vendor/nikic/*" "/samlsso/vendor/phar-io/*" "/samlsso/vendor/phpunit/*" "/samlsso/vendor/sebastian/*" "/samlsso/vendor/theseer/*"
	else
		echo "/samlsso not found at `pwd`";
	fi
else
	echo "Directory $GLPIPATH doesnt exist!";
fi
