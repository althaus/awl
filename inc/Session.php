<?php
// Session handling
// - set up the session object

require_once('PgQuery.php');

if ( isset($_GET['logout']) ) {
  error_log("$sysname: Session: DBG: Logging out");
  setcookie( 'sid', '', 0,'/');
  unset($_COOKIE['sid']);
  unset($_COOKIE['lsid']); // Allow a cookied person to be un-logged-in for one page view.

  if ( isset($_GET['forget']) ) setcookie( 'lsid', '', 0,'/');
}

$session = new Session();

if ( isset($_POST['username']) && isset($_POST['password']) ) {
  // Try and log in if we have a username and password
  $session->Login( $_POST['username'], $_POST['password'] );
  if ( $debuggroups['Login'] )
    $session->Log( "DBG: User $_POST[username] - $session->fullname ($session->user_no) login status is $session->logged_in" );
}
else if ( !isset($_COOKIE['sid']) && isset($_COOKIE['lsid']) && $_COOKIE['lsid'] != "" ) {
  // Validate long-term session details
  $session->LSIDLogin( $_COOKIE['lsid'] );
  if ( $debuggroups['Login'] )
    $session->Log( "DBG: User $session->username - $session->fullname ($session->user_no) login status is $session->logged_in" );
}

function session_salted_md5( $instr, $salt = "" ) {
  global $debuggroups, $session;
  if ( $salt == "" ) $salt = substr( md5(rand(100000,999999)), 2, 8);
  if ( $debuggroups['Login'] )
    $session->Log( "DBG: Making salted MD5: salt=$salt, instr=$instr, md5($salt$instr)=".md5($salt . $instr) );
  return ( sprintf("*%s*%s", $salt, md5($salt . $instr) ) );
}

function session_validate_password( $they_sent, $we_have ) {
  global $debuggroups, $session;

  if ( $debuggroups['Login'] )
    $session->Log( "DBG: Comparing they_sent=$they_sent with $we_have" );

  // In some cases they send us a salted md5 of the password, rather
  // than the password itself (i.e. if it is in a cookie)
  $pwcompare = $we_have;
  if ( ereg('^\*(.+)\*.+$', $they_sent, $regs ) ) {
    $pwcompare = session_salted_md5( $we_have, $regs[1] );
    if ( $they_sent == $pwcompare ) return true;
  }

  if ( ereg('^\*\*.+$', $we_have ) ) {
    //  The "forced" style of "**plaintext" to allow easier admin setting
    // error_log( "$system_name: vpw: DBG: comparing=**they_sent" );
    return ( "**$they_sent" == $pwcompare );
  }

  if ( ereg('^\*(.+)\*.+$', $we_have, $regs ) ) {
    // A nicely salted md5sum like "*<salt>*<salted_md5>"
    $salt = $regs[1];
    $md5_sent = session_salted_md5( $they_sent, $salt ) ;
    if ( $debuggroups['Login'] )
      $session->Log( "DBG: Salt=$salt, comparing=$md5_sent with $pwcompare or $we_have" );
    return ( $md5_sent == $pwcompare );
  }

  // Blank passwords are bad
  if ( "" == "$we_have" || "" == "$they_sent" ) return false;

  // Otherwise they just have a plain text string, which we
  // compare directly, but case-insensitively
  return ( $they_sent == $pwcompare || strtolower($they_sent) == strtolower($we_have) );
}

class Session
{
  var $user_no = 0;
  var $session_id = 0;
  var $username = 'guest';
  var $full_name = 'Guest';
  var $email = '';
  var $roles;
  var $logged_in = false;
  var $cause = '';
  var $just_logged_in = false;

  function Session()
  {
    $this->roles = array();
    $this->logged_in = false;
    $this->just_logged_in = false;

    if ( ! isset($_COOKIE['sid']) ) return;

    list( $session_id, $session_key ) = explode( ';', $_COOKIE['sid'], 2 );

    $sql = "SELECT session.*, usr.*, organisation.*
        FROM session, usr, organisation
        WHERE usr.user_no = session.user_no
        AND session_id = ?
        AND (md5(session_start::text) = ? OR session_key = ?)
        AND organisation.org_code = usr.org_code
        ORDER BY session_start DESC LIMIT 1";

    $qry = new PgQuery($sql, $session_id, $session_key, $session_key);
    if ( $qry->Exec('Session') && $qry->rows == 1 )
    {
      $this->AssignSessionDetails( $qry->Fetch() );
      $qry = new PgQuery('UPDATE session SET session_end = current_timestamp WHERE session_id=?', $session_id);
      $qry->Exec('Session');
    }
    else
    {
      //  Kill the existing cookie, which appears to be bogus
      setcookie('sid', '', 0,'/');
      $this->cause = 'ERR: Other than one session record matches. ' . $qry->rows;
      $this->Log( "WARN: Login $this->cause" );
    }
  }

  function Log( $whatever )
  {
    global $c;

    $argc = func_num_args();
    $format = func_get_arg(0);
    if ( $argc == 1 || ($argc == 2 && func_get_arg(1) == "0" ) ) {
      error_log( "$c->sysabbr: $format" );
    }
    else {
      $args = array();
      for( $i=1; $i < $argc; $i++ ) {
        $args[] = func_get_arg($i);
      }
      error_log( "$c->sysabbr: " . vsprintf($format,$args) );
    }
    return true;
  }

  function AllowedTo ( $whatever ) {
    return ( $this->logged_in && isset($this->roles[$whatever]) && $this->roles[$whatever] );
  }


  function GetRoles () {
    $this->roles = array();
    $qry = new PgQuery( 'SELECT group_name AS role_name FROM group_member m join ugroup g ON g.group_no = m.group_no WHERE user_no = ? ', $this->user_no );
    if ( $qry->Exec('Session::GetRoles') && $qry->rows > 0 ) {
      while( $role = $qry->Fetch() ) {
        $this->roles[$role->role_name] = true;
      }
    }

    $this->system_roles = array();
    $qry = new PgQuery( 'SELECT system_code, role FROM system_usr WHERE user_no = ? ', $this->user_no );
    if ( $qry->Exec('Session::GetRoles') && $qry->rows > 0 ) {
      while( $role = $qry->Fetch() ) {
        $this->system_roles[$role->system_code] = $role->role;
      }
    }
  }


  function AssignSessionDetails( $u ) {
    $this->user_no = $u->user_no;
    $this->username = $u->username;
    $this->fullname = $u->fullname;
    $this->email = $u->email;
    $this->org_code = $u->org_code;
    $this->org_name = $u->org_name;
    $this->config_data = $u->config_data;
    $this->session_id = $u->session_id;

    $this->GetRoles();
    $this->logged_in = true;
  }


  function Login( $username, $password ) {
    global $c, $debuggroups;
    if ( $debuggroups['Login'] )
      $this->Log( "DBG: Login: Attempting login for $username" );

    $sql = "SELECT * FROM usr WHERE lower(username) = ? ";
    $qry = new PgQuery( $sql, strtolower($username), md5($password), $password );
    if ( $qry->Exec('Session::UPWLogin') && $qry->rows == 1 ) {
      $usr = $qry->Fetch();
      if ( session_validate_password( $password, $usr->password ) ) {
        // Now get the next session ID to create one from...
        $qry = new PgQuery( "SELECT nextval('session_session_id_seq')" );
        if ( $qry->Exec('Login') && $qry->rows == 1 ) {
          $seq = $qry->Fetch();
          $session_id = $seq->nextval;
          $session_key = md5( rand(1010101,1999999999) . microtime() );  // just some random shite
          if ( $debuggroups['Login'] )
            $this->Log( "DBG:: Login: Valid username/password for $username ($usr->user_no)" );

          // And create a session
          $sql = "INSERT INTO session (session_id, user_no, session_key) VALUES( ?, ?, ? )";
          $qry = new PgQuery( $sql, $session_id, $usr->user_no, $session_key );
          if ( $qry->Exec('Login') ) {
            // Assign our session ID variable
            $sid = "$session_id;$session_key";

            //  Create a cookie for the sesssion
            setcookie('sid',$sid, 0,'/');
            // Recognise that we have started a session now too...
            $this->Session();
            $this->Log( "DBG: Login: INFO: New session $session_id started for $username ($usr->user_no)" );
            if ( isset($_POST['remember']) && intval($_POST['remember']) > 0 ) {
              $cookie .= md5( $this->user_no ) . ";";
              $cookie .= session_salted_md5($usr->user_no . $usr->username . $usr->password);
              setcookie( "lsid", $cookie, time() + (86400 * 3600), "/" );   // will expire in ten or so years
            }
            $this->just_logged_in = true;
            unset($_POST['submit']);
            unset($_GET['submit']);
            unset($GLOBALS['submit']);
            return true;
          }
   // else ...
          $this->cause = 'ERR: Could not create new session.';
        }
        else {
          $this->cause = 'ERR: Could not increment session sequence.';
        }
      }
      else {
        $client_messages[] = 'Invalid username or password.';
        if ( $debuggroups['Login'] )
          $this->cause = 'WARN: Invalid password.';
        else
          $this->cause = 'WARN: Invalid username or password.';
      }
    }
    else {
    $client_messages[] = 'Invalid username or password.';
    if ( $debuggroups['Login'] )
      $this->cause = 'WARN: Invalid username.';
    else
      $this->cause = 'WARN: Invalid username or password.';
    }

    $this->Log( "DBG: Login $this->cause" );
    return false;
  }



  function LSIDLogin( $lsid ) {
    global $sysname, $debuggroups, $client_messages, $sid;
    if ( $debuggroups['Login'] )
      $this->Log( "DBG: Login: Attempting login for $lsid" );

    list($md5_user_no,$validation_string) = split( ';', $lsid );
    $qry = new PgQuery( "SELECT * FROM usr WHERE md5(user_no)=?;", $md5_user_no );
    if ( $qry->Exec('Session::LSIDLogin') && $qry->rows == 1 ) {
      $usr = $qry->Fetch();
      list( $x, $salt, $y) = split('\*', $validation_string);
      $my_validation = session_salted_md5($usr->user_no . $usr->username . $usr->password, $salt);
      if ( $validation_string == $my_validation ) {
        // Now get the next session ID to create one from...
        $qry = new PgQuery( "SELECT nextval('session_session_id_seq')" );
        if ( $qry->Exec('Login') && $qry->rows == 1 ) {
          $seq = $qry->Fetch();
          $session_id = $seq->nextval;
          $session_key = md5( rand(1010101,1999999999) . microtime() );  // just some random shite
          if ( $debuggroups['Login'] )
            $this->Log( "DBG:: Login: Valid username/password for $username ($usr->user_no)" );

          // And create a session
          $sql = "INSERT INTO session (session_id, user_no, session_key) VALUES( ?, ?, ? )";
          $qry = new PgQuery( $sql, $session_id, $usr->user_no, $session_key );
          if ( $qry->Exec('Login') ) {
            // Assign our session ID variable
            $sid = "$session_id;$session_key";

            //  Create a cookie for the sesssion
            setcookie('sid',$sid, 0,'/');
            // Recognise that we have started a session now too...
            $this->Session();
            $this->Log( "DBG: Login: INFO: New session $session_id started for $this->username ($usr->user_no)" );
            return true;
          }
   // else ...
          $this->cause = 'ERR: Could not create new session.';
        }
        else {
          $this->cause = 'ERR: Could not increment session sequence.';
        }
      }
      else {
        $this->Log("DBG: $validation_string != $my_validation ($salt - $usr->user_no, $usr->username, $usr->password)");
        $client_messages[] = 'Invalid username or password.';
        if ( $debuggroups['Login'] )
          $this->cause = 'WARN: Invalid password.';
        else
          $this->cause = 'WARN: Invalid username or password.';
      }
    }
    else {
    $client_messages[] = 'Invalid username or password.';
    if ( $debuggroups['Login'] )
      $this->cause = 'WARN: Invalid username.';
    else
      $this->cause = 'WARN: Invalid username or password.';
    }

    $this->Log( "DBG: Login $this->cause" );
    return false;
  }


  function LoginRequired( $groups = "" ) {
    global $c, $session;

    if ( $this->logged_in && $groups == "" ) return;
    if ( ! $this->logged_in ) {
      $c->messages[] = "You must log in to use this system.";
      include("page-header.php");
      if ( function_exists("local_index_not_logged_in") ) {
        local_index_not_logged_in();
      }
      else {
        echo <<<EOTEXT
<h1>Log On Please</h1>
<p>For access to the $c->system_name you should log on with
the username and password that have been issued to you.</p>

<p>If you would like to request access, please e-mail $c->admin_email.</p>
<form action="$action_target" method="post">
<h2>User Name</h2>
<p><input type="text" name="username" size="12"></p>
<h2>Password</h2>
<p><input type="password" name="password" size="12"></p>
<p>
 &nbsp;forget&nbsp;me&nbsp;not: <input type="checkbox" name="remember" value="1">
<input type="submit" value="GO!" alt="go" name="submit" class="submit">
</p>
</form>

EOTEXT;
      }
    }
    else {
      $valid_groups = split(",", $groups);
      foreach( $valid_groups AS $k => $v ) {
        if ( $this->AllowedTo($v) ) return;
      }
      include("page-header.php");
      $c->messages[] = "You are not authorised to use this function.";
    }

    include("page-footer.php");
    exit;
  }
}

?>