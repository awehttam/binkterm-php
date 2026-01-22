@echo off
php scripts\binkp_poll.php --all 
goto :end
php scripts\process_packets.php
php scripts\binkp_poll.php --all


:end
