#!/bin/sh
# Script to copy package files into their final position in the unRAID runtime path.
# This can be run if the plugin is already installed without having to build the
# package or commit any changes to gitHub.   Make testing increments easier.
# (useful during testing)
#
# Copyright 2019, Dave Walker (itimpi).
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License version 2,
# as published by the Free Software Foundation.
#
# Limetech is given expliit permission to use this code in any way they like.
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
PLUGIN="parity.check.tuning"

echo "copying files from 'source' to runtime position"
cp -v -r source/* / &>/dev/null
chown -R root /usr/local/emhttp/plugins/$PLUGIN/*
chgrp -R root /usr/local/emhttp/plugins/$PLUGIN/*
chmod -R 755 /usr/local/emhttp/plugins/$PLUGIN/*
date
echo "files copied "
