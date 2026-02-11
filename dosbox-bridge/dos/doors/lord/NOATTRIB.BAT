@ECHO OFF
echo
echo This undoes what DOATTRIB.BAT does.  (run before upgrading LORD)
echo                     
@ECHO ON
attrib -R lord.exe
attrib -R lord.ovr
attrib -R lordtxt.dat
attrib -R lordrip.dat
