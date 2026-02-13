@echo off
rem
rem  A batch file for use with DWHost and Doorway
rem
C:
rem CHANGE THE ABOVE DRIVE SPEC TO THAT ON WHICH DW HOST RESIDES.
cd\dwhost
rem CHANGE THE ABOVE DIRECTORY TO THAT IN WHICH DW HOST RESIDES.
if exist quit del quit
if exist okay del okay
DWhost %1
if exist quit goto done
rem The above statement allows exit if you hit F1 or F2 within DW Host.
if not exist okay goto off
del okay
doorway COM1 /g:on /m:100 /O: /S:* /v:d^U /R:^X /I:dropmenu /c:dos
rem The above line can be edited for you own optimization. IE. use COM2
:off
DWhost o
rem   The above line invokes DWHost with the O parm to cause logoff and
rem     to drop DTR.
rem   And the line below re-invokes this batch file from the beginning so
rem     that DW Host will recycle to await the next call.
%0 %1
:done
