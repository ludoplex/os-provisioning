#!/bin/bash

# add current git commit hash as version to doxygen
# see https://christianhujer.github.io/Git-Version-in-Doxygen
export PROJECT_NUMBER="$(git rev-parse HEAD ; git diff-index --quiet HEAD || echo '(with uncommitted changes)')"

BASEDIR="/var/www/lara/Documentation"
doxygen $BASEDIR/doxyfile

echo
echo "Warnings from last run are stored in $BASEDIR/doxywarn.log"
echo
