#!/bin/bash
# *******************************************************
# * BBSlink.net InterBBS Door Server Connection Script	*
# *******************************************************
# *  Created by: Dennis Ayala			  	            *
# *  Virtual Realities BBS				                *
# *  telnet://bbs.virtualrealitiesbbs.com		        *
# *******************************************************
#
#  PLEASE DO NOT DISTRIBUTE THIS FILE
#  ==================================
#
#  Version 1.0.4  19th December 2015
#
#  (C)2015 Dennis Ayala for BBSlink.net.
#
#  Thanks go to haliphax for cryptography advice.
#
#
#  USAGE:
#  =====
#
#  1. Place this script in an appropriate directory that is
#     accessible by your BBS software.
#
#  2. Configure the script with your system code, authorisation
#     code and scheme code below.
#
#  3. Change permissions with the following command:
#
#          chmod +x bbslink.sh
#
#  3. From the BBS door setup, call the script with the following command:
#
#          bbslink.sh [door code] [user number]
#
#     i.e. bbslink.sh lord 27
#
#  4. You can call different door games by changing the argument
#     in the command line. An up-to-date list of available doors
#     can always be found at http://www.bbslink.net/sysop
#
#  I hope you find BBSlink.net useful. Please guard your BBSlink login
#  credentials carefully, and should your system's authorisation code be
#  compromised, please contact me immediately for a new one.
#
#  All feedback is greatly appreciated!
#
#  Mark Mearns.
#
# *******************************************************
# * Configure the following settings for your system: 	*
# *******************************************************
host="games.bbslink.net" 		# Server address, usually 'games.bbslink.net'
syscode=""					    # Your system code
authcode=""				 		# Your system's authorisation code
schemecode=""					# Scheme code

if ! command -v curl &> /dev/null; then
	echo "SYSOP: curl is not installed"
	exit 1
fi

if ! command -v telnet &> /dev/null; then
	echo "SYSOP: telnet is not installed"
	exit 1
fi

DIR="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

if [ ! -r "$DIR/vars.sh" ]; then
	echo "SYSOP: copy vars.sh.example to vars.sh and edit with your settings"
	exit 1
fi

source "$DIR/vars.sh"

# *******************************************************
# * DO NOT EDIT BELOW THIS LINE 			*
# *******************************************************
version="1.0.4"
screenrows="24"
scripttype="bash"
scriptver="$version"
# *******************************************************
# * PROCESS ARRGUMENTS		                        *
# *******************************************************
if [ "$#" -lt 2 ]; then
        echo ""
        echo "Usage: bbslink.sh [Door Code] [User Number]"
        echo "Requires 2 arguments: door code and user number"
        echo ""
        echo "Example:"
        echo "bbslink.sh lord 27"
        echo ""
        exit 1
else
	# *******************************************************
	# * GET COMMAND-LINE PARAMETERS                         *
	# *******************************************************
	doorcode="$1"
	usernumber="$2"
	# *******************************************************
	# * Generate random 32 character alphanumeric string 	*
	# * (upper and lowercase)				*
	# *******************************************************
	xkey=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w 6 | head -n 1)
	# *******************************************************
	# * Get Token from BBSLink				*
	# *******************************************************
	token=$(curl -s "http://$host/token.php?key=$xkey")
	# *******************************************************
	# * HASH AUTHCODE & SCHEME CODE		                *
	# *******************************************************
	xauth=$(echo -n "$authcode$token" | md5sum | awk '{print $1}')
	xcode=$(echo -n "$schemecode$token" | md5sum | awk '{print $1}')
	# *******************************************************
	# * AUTHENTICATE ON BBSLINK	                        *
	# *******************************************************
	result=$(curl -s -H "X-User: $usernumber" -H "X-System: $syscode" -H "X-Auth: $xauth" -H "X-Code: $xcode" -H "X-Rows: $screenrows" -H "X-Key: $xkey" -H "X-Door: $doorcode" -H "X-Token: $token" -H "X-Type: $scripttype" -H "X-Version: $scriptver" "http://$host/auth.php?key=$xkey")
	if [ "$result" == "complete" ]; then
		# *******************************************************
       	# * LAUNCH BBS LINK SESSION                             *
       	# *******************************************************
		export TERM=linux
		clear
		echo " "
		echo "  BBSlink.net Linux connection client v$version"
		echo " "
		echo "  Courtesy of Virtual Realities BBS - telnet:/bbs.virtualrealitiesbbs.com "
		echo " "
		echo "  Connecting to BBSlink.net, please wait..."
		echo " "
		sleep 2
		telnet -E -K -8 $host
	else
		# *******************************************************
        # * AUTHENTICATION FAILED                               *
        # *******************************************************
		echo "Error: $result"
		exit 1
	fi
fi

exit 0