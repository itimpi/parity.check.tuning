#| /bin/sh
# Script to run a get /proc/mdstat variables we are interested in
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

cat /proc/mdstat | grep "mdResync"
