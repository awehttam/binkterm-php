@ECHO OFF
echo
echo Run this batch file once if you use Lantastic.  If makes the main LORD
echo files readonly.  Run NOATTRIB.BAT to undo this.  (undo when upgrading,
echo or new files won't be copied over your old ones)
echo
attrib +R lord.exe
attrib +R lord.ovr
attrib +R lordtxt.dat
attrib +R lordrip.dat
