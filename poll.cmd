@echo off
rem php scripts\binkp_poll.php --all  --log-level=DEBUG
php scripts\binkp_poll.php --all  
rem goto :end
php scripts\process_packets.php
php scripts\binkp_poll.php --all


:end
