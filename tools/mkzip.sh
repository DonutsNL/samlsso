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

# This script requires zip to be installed.
# in debian install it via apt install zip first.

OLDVERSION='1.2.7'
NEWVERSION='1.3.0'

# Figure out what the GLPIpath is.
FULLPATH=$(readlink -f "$0")
KNOWN_SUFFIX="/samlsso/tools/mkzip.sh"
GLPIPATH="${FULLPATH%$KNOWN_SUFFIX}"

# Verify GLPIPATH points to an directory.
if [ -d "$GLPIPATH" ]; then
	find "$GLPIPATH/samlsso" -type f -name "*.php" -not -path "*/vendor/*" -exec sed -i "s/$OLDVERSION/$NEWVERSION/g" {} +

	# Run automated tests and verify success before release
	echo "Running automated test suite..."
	php "$GLPIPATH/samlsso/tests/RunAllTests.php"
	TEST_RESULT=$?
	if [ $TEST_RESULT -ne 0 ]; then
		echo "❌ Automated test suite failed! Release package will not be created."
		exit $TEST_RESULT
	fi
	echo "✅ Automated test suite passed successfully."

	# Remove old zipfiles
	if [ -f "$GLPIPATH/samlsso/release/samlsso.zip" ]; then
		rm -f $GLPIPATH/samlsso/release/samlsso.zip
	fi
	if [ -f "$GLPIPATH/samlsso/release/release.zip" ]; then
		rm -f $GLPIPATH/samlsso/release/release.zip
	fi
	
	cd $GLPIPATH;
	if [ -d './samlsso' ]; then 
		zip -r ./samlsso/release/samlsso.zip ./samlsso -x "/samlsso/tools/*" "/locales/samlSSO.pot" "/samlsso/tests/*" "/samlsso/samlsso.xml" "/samlsso/.vscode/*" "/samlsso/.gitignore" "/samlsso/.github/*" "/samlsso/.git/*" "/samlsso/release/*" "/samlsso/composer.lock" "/samlsso/vendor/bin/*" "/samlsso/vendor/myclabs/*" "/samlsso/vendor/nikic/*" "/samlsso/vendor/phar-io/*" "/samlsso/vendor/phpunit/*" "/samlsso/vendor/sebastian/*" "/samlsso/vendor/theseer/*"
	else
		echo "/samlsso not found at `pwd`";
	fi
else
	echo "Directory $GLPIPATH doesnt exist!";
fi
