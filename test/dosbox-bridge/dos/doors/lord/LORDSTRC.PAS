{  A quick note as of 8-31-97:  The entire source to Seth's IGM Barak's }
{  house is available for download at www.rtsoft.com. A good place to   }
{  start if you want to make an easy to install IGM that runs great!    }

{  These are the pascal file structures for the players and enemies for }
{  Legend Of The Red Dragon 3.20 to 3.53a This file was last updated on }
{  12-12-95.  For software developers.                                  }                                        }
{                                                                       }
{  3.53a does NOT change the format of the PLAYER.DAT file.  Carry on!  }
{                                                                       }
{  Check the BOTTOM of this file for NEW things about 3.53a developers  }
{  should know.                                                         }
{                                                                       }
{  Legend Of The Red Dragon                                             }
{  (c) Copyright 1995; Robinson Technologies                            }
{  ALL RIGHTS RESERVED.                                                 }
{                                                                       }
{                                                                       }
{  Note from Seth A. Robinson:  You have my permission to build         }
{  add-ons, editers, whatever - BUT, if you decide to charge money for  }
{  them I would appreciate it if you would upload me your file          }
{  privately, let me evualuate it and see if it deserves my stamp of    }
{  approval.                                                            }
{                                                                       }
{  That's right, I'm not against others making money off their mods, in }
{  fact, I don't demand any percentage whatsoever.  God knows I've made }
{  much more than I deserve already.  Just try not to charge more than  }
{  I do for LORD itself, k?                                             }

{  Also, in ANY util for LORD, be sure to give some credit to ME! <G>   }

{  If you are going to build a LORD In Game Module, you *MUST* meet     }
{  these specifications to call it such:                                }
{                                                                       }
{  1.  It reads *NO* external drop file, but gets all data from the     }
{      info.? file AND node?.dat file.                                  }
{                                                                       }
{  2.  It has options to install *AND* uninstall itself.  (ie, add the  }
{      program to the 3RDPARTY.DAT file itself)                         }
{                                                                       }
{  3.  The top line if the file_id.diz is like this:                    }
{      <NAME & VERSION OF YOUR PROG> LORD IGM  (Decorate if you like)   }   

{  The reason I'm giving these specific instructions is because a few   }
{  others have released their software as IGM's, sysops expect the      }
{  simple installation they got with Barak's House                      }
{  and instead find themselves having to mess with writing dropfiles for}
{  the LORD mod separatly - which can be a mess, especially if the drop }
{  file is overwritten while someone else goes into the realm, ect.     }

{  *PLEASE* abide by these rules.  If you don't - I, uh, will send      }
{  someone to break your thumbs! ;>                                     }

{  Mail and color codes:  A "`" (not a "'") proceeded by certain symbols}
{  and numbers have special funtions when LORD reads them in someones   }
{  mail.  Except for colors, these codes must be at the beginning of a  }
{  line.  Look at some MAILXX.DAT files for examples.  Here is the list }
{  of codes - for instance, you may want your 3rd party program to give }
{  a certain player one more charm point - there is a code to do that!  }

{*

NOTE:  In most cases, such as adding experience, if you put a negetive
amount, it will DECREASE the value.  It does do checking to make sure it's
not less then 1 when done.

`1 through `0, and `! through `% are colors.  (15 of them)
          (`^ was removed.  Who wants all black forground color?)
The following codes MUST be the first and only thing on the line.

`-<account num>  This will have LORD give the 'Write <name> back?' option.
`b<amount> this deposites the amount in the readers bank account.
`G<amount> this puts the amount in the readers 'money in hand'.
`E<amount> this adds to the readers experience by amount.
`?<account num>  Makes user MARRIED to this person. (-1 for not married)
`{ this increments the 'number of lays' stat of the reader.
`} {this increments the readers charm.)
`+<amount>  Users charm BECOMES this number. (Used in divorce mail-backs)
`K this increments the amount of kids the reader has.
`M<amount>  This increments readers Strength
`D<amount>  This increments readers Defence
`,<amount>  This increments forest fights per that day
`:<amount>  This increments user fighters per that day
`;<amount>  This increments readers Hitpoint Max

   (Note, the above mail codes can decrement if a - is placed before num.
   Checking is done to make sure user does not go below certain levels)

`S This raises the readers SKILL in his current class.  Will not let the
   skill pass 40.
`T <account num>  Indicates <account num> wants to flirt with reader
`Y <account num>  Indicates <account num> wants to kiss reader
`U <account num>  Indicates <account num> wants to have dinner with reader
`I <account num>  Indicates <account num> wants to sleep with reader
`P <account num>  Indicates <account num> wants to propose to reader
`O <account num> Begins an online battle with <account num> (Don't use this!)
`c clears, homes the screen and goes down two lines.

     *****  HOW DO I MAKE AN IN GAME MODULE FOR LORD? *****

  It is really sort of easy.  The smartest way is to write your door
  to be able to read the NODEX.DAT files - (For com port, port speed, ect)
  these are text editable, so you should be able to figure them out ok.

  It comes down to this:  Someone will be able to unzip your program, and
  run the CFG/Installer, and it will add two lines to the 3RDPARTY.DAT
  file.  These two lines are ALL LORD needs to automatically list your
  door name as it appears to the players, (second line) and the path and
  name of the EXE or BAT file that is run.  Look at the 3RDPARTY.DAT
  file, it comes empty, but does contain a commented out example.
  Note:  the 3RDPARTY.DAT file is automatically created when LORD is
  run if it doesn't exist.


  Notice that a * anywhere in the first line will have LORD subsitute
  the node number.  So with say "C:\LORD\NEWBAR.EXE /N*" would give your
  program the /N6 parm if node 6 was playing.  LORD will go up to 999
  nodes.  (In theory only <G> )

  Why is this important?  Because you can then retrieve the correct NODEX.DAT
  file yourself from the LORD dir.  LORD will drop a file called INFO.<node
  num>, it contains the account number of the user who just dropped.
  (this file also contains extra data, pherhaps enough so your program
  will not NEED to open another file)

  Your door should pick up that, (it's a text file) and load that record.
  Let them use the door, and write it upon exitting.  This means you could
  load different INFO and NODE data for each node - thereby making your
  addon multi-node.  Simple, eh?

  Another thing.  The max "Add On's" 3.50+ can handle is a lot.  At least a
  thousand.

  Please be considerate enough to throw a 'Uninstall' option in your
  configure program - To find your two lines and strip them out, just
  in case the sysop decides not to keep it.  Sure, most sysops can edit
  the 3RDPARTY.DAT file themselves, but it's a nice option anyway.

  There you have it - The goal of this is to make installing add-ons
  much easier then installing a whole new door, because they will not
  have to re-configure any com port settings, or node settings, because
  you will be reading the NODEX.DAT files.  This also means you don't
  have to mess with drop files at all, since using the 'account number'
  from the INFO.??? file lets you load the entire account, including their
  BBS handle and LORD handle of course.

  The structure of the LORD.<node num> file:

  13      <-Account, from 0 to 149
  3       <-Graphic Setting, 3 is ANSI, less is ASCII
  RIP YES <-Obvious - It's RIP NO when they are not RIP.
  FAIRY YES <- Do they have a fairy with them?
  Time Left <- In minutes.  This is READ AGAIN when they come back.  You
               should keep track and change this number accordingly, so LORD
               will how much time to give them.
  LORD Handle       \
  Real First Name    \
  Real Last Name      \
  Com Port;           |  Other info you may find useful.  You should still 
  Caller Baud Rate;  /   read the NODEX.DAT files, because they contain
  Port Baud Rate    /    more info - Such as a nonstardard com port.
  
  -=-=New things added to the info.? file in 3.26=-=-
  Note:  LORD never reads any of these in, only writes them for your
  convenience.

  FOSSIL  <- (will say INTERNAL if they are using LORD's internal routines)
  REGISTERED <- LORD registration status, else UNREGISTERED.
  CLEAN MODE ON <- else CLEAN MODE OFF

  NOTE:  Look at the INFO.? file yourself while someone is in a In
  Game Module from LORD, you'll notice there are NO spaces in the file.

}
program LordStrc;
uses dos,crt;




 {player record}

type player_info = record
   names: string[20]; {player handle in the game}
   real_names: string[50] {real name/or handle from BBS} ;
   hit_points  {player hit points}
  ,bad  {don't know - might not be used at all}
  ,rate: integer; {again, couldn't find this one in the source}
  hit_max: integer; {hit_point max}
  weapon_num: integer; {weapon number}
  weapon: string[20]; {name of weapon}
  seen_master: integer; {equals 5 if seen master, else 0}
  fights_left: integer; {forest fights left}
  human_left: integer; {human fights left}
  gold: longint; {gold in hand}
  bank: longint; {gold in bank}
  def: integer;  {total defense points }
  strength: integer; {total strength}
  charm: integer; {good looking meter}
  seen_dragon: integer; {seen dragon?  5 if yes else 0}
  seen_violet: integer; {seen violet?  5 if yes else 0}
  level: integer; {level of player}
  time: word; {day # that player last played on}
  arm: string[20]; {armour name}
  arm_num: integer; {armour number}
  dead: shortint; {player dead?  5 if yes else 0}
  inn: shortint; {player sleeping at inn?  5 if yes else 0}
  gem: integer; {# of gems on hand}
  exp: longint; {experience}
  sex: shortint; {gender, 5 if female else 0}
  seen_bard: shortint; {seen bard?  5 if yes else 0}
  last_alive_time: integer; {day # player was last reincarnated on}
  Lays: integer; {players lays stat}
  Why: integer; {not used yet}
  on_now: boolean; {is player on?}
  m_time: integer; {day on_now stat was last used}
  time_on: string[5]; {time player logged on in Hour:Minutes format}
  class: shortint; {class, should be 1, 2 or 3}
  extra: integer;      {*NEW*  If 1, player has a horse}
  love: string[25]; {not used - may be used for inter-player marrages later}
  married: integer; {who player is married to, should be -1 if not married}
  kids: integer; {# of kids}
  king: integer; {# of times player has won game}
  skillw: shortint; {number of Death Knight skill points}
  skillm: shortint; {number of Mystical Skills points}
  skillt: shortint; {number of Thieving Skills points}

  levelw: shortint; {number of Death Knight skill uses left today}
  levelm: shortint; {number of Mystical skill uses left today}
  levelt: shortint; {number of Thieving skill uses left today}

  inn_random: boolean; {not used yet}
  married_to: integer; {same as Married, I think - don't know why it's here}
  v1: longint;
  v2: integer; {# of player kills}
  v3: integer; {if 5, 'wierd' event in forest will happen}
  v4: boolean; {has player done 'special' for that day?}
  v5: shortint; {has player flirted with another player that day?  if so, 5}
  new_stat1: shortint;
  new_stat2: shortint;  {these 3 are unused right now}
  new_stat3: shortint;  {Warning: Joseph's NPCLORD screws with all three}
end;

{  Remember, in 3.20+ player skill points for each class can reach 40.      }

{  It's ok to have your editer change the player level to say, 12 or        }
{  such, LORD will automatically not let the player play if the game is     }
{  not registered.                                                          }

{  If in your program you let the player get a new weapon, remember, you CAN}
{  change the name of his weapon,  but the weapon NUMBER stat is what tells }
{  LORD which weapon he has, and if he sells it, will SUBTRACT the correct  }
{  number from his overall strength.  Kind of complicated.  Armour works the}
{  exact same way.                                                          }

{List of armour info - Num is the defense added or subtracted when bought or}
{sold.

   if number= 1 then wep_name := 'Coat';
     if number= 1 then price := 200;
     if number= 1 then num :=1;
     if number= 2 then wep_name := 'Heavy Coat';
     if number= 2 then price := 1000;
     if number= 2 then num :=3;
     if number= 3 then wep_name := 'Leather Vest';
        if number= 3 then num :=10;

     if number= 3 then price := 3000;
     if number= 4 then wep_name := 'Bronze Armour';
     if number= 4 then price := 10000;
     if number= 4 then num :=15;
     if number= 5 then wep_name := 'Iron Armour';
     if number= 5 then price := 30000;
      if number=5 then num :=25;
      if number= 6 then wep_name := 'Graphite Armour';
     if number= 6 then price := 100000;
     if number = 6 then num := 35;
     if number= 7then wep_name := 'Erdricks Armour';
     if number= 7 then price := 150000;
     if number= 7 then num := 50;
     if number= 8 then wep_name := 'Armour Of Death';
     if number= 8 then price := 200000;
     if number= 8 then num := 75;
     if number= 9 then wep_name := 'Able''s Armour';
     if number= 9 then price := 400000;
     if number= 9 then num := 100;
     if number= 10 then wep_name := 'Full Body Armour';
     if number= 10 then price := 1000000;
     if number= 10 then num := 150;
     if number= 11 then wep_name := 'Blood Armour';
     if number= 11 then price := 4000000;
     if number= 11 then num:= 225;
     if number= 12 then wep_name := 'Magic Protection';
     if number= 12 then price := 10000000;
     if number= 12 then num:= 300;
     if number= 13 then wep_name := 'Belars''s Mail';
     if number= 13 then price := 40000000;
     if number= 13 then num:=400;
     if number= 14 then wep_name := 'Golden Armour';
     if number= 14 then price := 100000000;
     if number= 14 then num:=600;
     if number= 15 then wep_name := 'Armour Of Lore';
     if number= 15 then price := 400000000;
     if number= 15 then num:=1000;

 Same info for weapons:

   if number= 1 then wep_name := 'Stick';
     if number= 1 then price := 200;
     if number= 1 then num := 5;
     if number= 2 then wep_name := 'Dagger';
     if number= 2 then price := 1000;
     if number= 2 then num:=10;
     if number= 3 then wep_name := 'Short Sword';
     if number= 3 then price := 3000;
     if number= 3 then num := 20;
     if number= 4 then wep_name := 'Long Sword';
     if number= 4 then price := 10000;
     if number= 4 then num:= 30;
     if number= 5 then wep_name := 'Huge Axe';
     if number= 5 then price := 30000;
     if number= 5 then num:= 40;
     if number= 6 then wep_name := 'Bone Cruncher';
     if number= 6 then price := 100000;
     if number= 6 then num:=60;
     if number= 7then wep_name := 'Twin Swords';
     if number= 7 then price := 150000;
     if number= 7 then num:=80;
     if number= 8 then wep_name := 'Power Axe';
     if number= 8 then price := 200000;
     if number= 8 then num:=120;
     if number= 9 then wep_name := 'Able''s Sword';
     if number= 9 then num := 180;
     if number= 9 then price := 400000;
      if number= 10 then wep_name := 'Wans''s Weapon';
     if number= 10 then price := 1000000;
     if number= 10 then num := 250;
     if number= 11 then wep_name := 'Spear Of Gold';
     if number= 11 then price := 4000000;
     if number= 11 then num:= 350;
     if number= 12 then wep_name := 'Crystal Shard';
     if number= 12 then price := 10000000;
     if number= 12 then num:= 500;
     if number= 13 then wep_name := 'Niras''s Teeth';
     if number= 13 then price := 40000000;
     if number= 13 then num:=800;
     if number= 14 then wep_name := 'Blood Sword';
     if number= 14 then price := 100000000;
     if number= 14 then num:=1200;
     if number= 15 then wep_name := 'Death Sword';
     if number= 15 then price := 400000000;
     if number= 15 then num:=1800;


  {forest monster record format}
 type monst = record
           name: string[60];
           strength: longint;
           gold: longint;
           weapon: string[60];
           exp_points: longint;
           hit_points: longint;
           death: string[100]; {shown when monster is killed by power move}
 end;

{  There are eleven monsters for each level - they are in the file in order.}
{                                                                           }
{  You cannot ADD monsters, but you can change them.  Or one possibilty     }
{  would to write an .EXE that is run before LORD is played each time that  }
{  randomly replaces some monsters in each level with new ones.             }
{                                                                           }

                   { ** NEW!  LORD'S FILE LOCKING ROUTINES! **}

{ Get BCShare or compatible file locking system.That is what LORD uses now. }

{ A 3.50 addition: People can STAY in other towns/ect! (IGM's)

No IGM currently uses this feature of this writing, (duh, since 3.50 is
required to use it, and it ain't released as I write this) but I'm HOPING
it would work ok.  ;>

Everytime a user enters an IGM, LORD creates a file called OUT.<player num>.
This a text file.  The first line is the description LORD will give someone
when they view 'who's online' or try to attack them.

The addition: 

You can let people exit straight back to the BBS by making the second line
read QUIT.  Specifying something else will now have LORD log them off, and
automatically return to THAT command line specified on they're next log on.

So if you rewrote the out.1 file while player num 1 was in your IGM like 
this:

is sleeping in Barak's house.
c:\barak\barak.exe *  

Then LORD would actually let that person stay at Barak's.  Specifying a 
second line also makes LORD display 'where they are' slightly differently so  
a 'who is online' would look like this:

Billy Bob is sleeping in Barak's house. 

No one can attack someone who is 'out of the realm' so be careful if you
use this function.  Note:  Before return a user to your IGM when they log
on the next day or whenever, it processes mail and "new day" routines if
needed. 

********* ABOUT MAIN.BAT ***********
This is a batch file run every day RIGHT AFTER LORD does its maint.  This
way you could run your IGM's maint, or add things to the daily log in LORD
ect, BEFORE anyone plays that day.                                        
                              
If you want to use this, do the following in your install program:

1. See if maint.bat exists.  If it doesn't, assume they are running an older
version, and tell them to  get 3.53a.

2. Check to see what is already in the MAINT.BAT file, if your IGM name or
whatever program you use is ALREADY in there, don't add it again, take it
out if you need to and add a different line, or just leave the old one in.

3. When someone uses the UNINSTALL option, make sure your program takes out
the additions in MAINT.BAT in a non destructive way.  Don't screw up another
IGM's stuff above or below yours.  Just like the 3rdparty.dat file, really.

4.  In your maint or whatever, display some kind of progress report, and the
IGM's name.  Sysops like to know what they are doing, if they happen to be
watching at midnight, give them a treat.  Don't clear the screen if you can
help it.  And DON'T require a keypress to continue or anything.

Also, it is technically possible to RUN an IGM this way - it creates an
INFO.? file just like normal.   Not sure how this would be handy but..


********** INFO ON BADWORDS.DAT *********

Check this file out, you'll understand it easily.  A thought is to run this
by all user inputs in your IGM, that way the 'swear patrol' is online even
out of LORD.  This ain't required, but hey it would be cool.

}

  begin;

  end.
