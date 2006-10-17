<?php
/**
* A class to handle reading, writing, viewing, editing and validating
* usr records.
*
* @package   awl
* @author    Andrew McMillan <andrew@catalyst.net.nz>
* @copyright Catalyst IT Ltd
* @license   http://gnu.org/copyleft/gpl.html GNU GPL v2
*/
require_once("AWLUtilities.php");

/**
* We need to access some session information.
*/
require_once("Session.php");

/**
* We use the DataEntry class for data display and updating
*/
require_once("DataEntry.php");

/**
* We use the DataUpdate class and inherit from DBRecord
*/
require_once("DataUpdate.php");

/**
* A class to handle reading, writing, viewing, editing and validating
* usr records.
* @package   awl
* @subpackage   User
*/
class User extends DBRecord {
  /**#@+
  * @access private
  */
  /**
  * A unique user number that is auto assigned on creation and invariant thereafter
  * @var string
  */
  var $user_no;

  /**
  * Something to prefix all field names with before rendering them.
  * @var string
  */
  var $prefix;

  /**#@-*/

  /**
  * The constructor initialises a new record, potentially reading it from the database.
  * @param int $id The user_no, or 0 if we are creating a new one
  * @param string $prefix The prefix for entry fields
  */
  function User( $id , $prefix = "") {
    global $session;

    // Call the parent constructor
    $this->DBRecord();

    $this->prefix = $prefix;

    $this->user_no = 0;
    $keys = array();

    $id = intval("$id");
    if ( $id > 0 ) {
      // Initialise
      $keys['user_no'] = $id;
      $this->user_no = $id;
    }

    // Initialise the record, possibly from the file.
    $this->Initialise('usr',$keys);
    $this->Read();
    $this->GetRoles();

    $this->EditMode = ( ($_GET['edit'] && $this->AllowedTo($this->WriteType))
                    || (0 == $this->user_no && $this->AllowedTo("insert") ) );

    if ( $this->user_no == 0 ) {
      dbg_error_log("User", "Initialising new user values");

      // Initialise to standard default values

    }
  }


  /**
  * Can the user do this?
  * @param string $whatever What the user wants to do
  * @return boolean Whether they are allowed to.
  */
  function AllowedTo ( $whatever )
  {
    global $session;

    $rc = false;
    if ( $session->AllowedTo("Admin") ) {
      $rc = true;
      dbg_error_log("User",":AllowedTo: Admin is always allowed to %s", $whatever );
      return $rc;
    }

    switch( strtolower($whatever) ) {

      case 'view':
        $rc = ( $session->AllowedTo("Admin")
                || ($this->user_no > 0 && $session->user_no == $this->user_no) );
        break;

      case 'update':
        $rc = ( $session->AllowedTo("Admin")
                || ($this->user_no > 0 && $session->user_no == $this->user_no) );
        break;

      case 'changepassword':
        $rc = ( $session->AllowedTo("Admin")
                || ($this->user_no > 0 && $session->user_no == $this->user_no)
                || ("insert" == $this->WriteType) );
        break;

      case 'admin':

      case 'create':

      case 'insert':
        $rc =  ( $session->AllowedTo("Admin") );
        break;

      default:
        $rc = ( isset($session->roles[$whatever]) && $session->roles[$whatever] );
    }
    dbg_error_log("User",":AllowedTo: %s is%s allowed to %s", $this->username, ($rc?"":" not"), $whatever );
    return $rc;
  }


  /**
  * Get the group memberships for the user
  */
  function GetRoles () {
    $this->roles = array();
    $qry = new PgQuery( 'SELECT role_name FROM role_member JOIN roles USING (role_no) WHERE user_no = ? ', $this->user_no );
    if ( $qry->Exec("User") && $qry->rows > 0 ) {
      while( $role = $qry->Fetch() ) {
        $this->roles[$role->role_name] = 't';
      }
    }
  }


  /**
  * Render the form / viewer as HTML to show the user
  * @return string An HTML fragment to display in the page.
  */
  function Render( ) {
    $html = "";
    dbg_error_log("User", ":Render: type=$this->WriteType, edit_mode=$this->EditMode" );

    $ef = new EntryForm( $REQUEST_URI, $this->Values, $this->EditMode );
    $ef->NoHelp();  // Prefer this style, for the moment

    if ( $ef->EditMode ) {
      $html .= $ef->StartForm( array("autocomplete" => "off" ) );
      if ( $this->user_no > 0 ) $html .= $ef->HiddenField( "user_no", $this->user_no );
    }

    $html .= "<table width=\"100%\" class=\"data\" cellspacing=\"0\" cellpadding=\"0\">\n";

    $html .= $this->RenderFields($ef);
    $html .= $this->RenderRoles($ef);

    $html .= "</table>\n";
    if ( $ef->EditMode ) {
      $html .= '<div id="footer">';
      $html .= $ef->SubmitButton( "submit", (("insert" == $this->WriteType) ? "Create" : "Update") );
      $html .= '</div>';
      $html .= $ef->EndForm();
    }

    return $html;
  }

  /**
  * Render the core details to show to the user
  * @param object $ef The entry form.
  * @param string $title The title to display above the entry fields.
  * @return string An HTML fragment to display in the page.
  */
  function RenderFields($ef , $title = "User Details" ) {
    global $session;

    $html = ( $title == "" ? "" : $ef->BreakLine($title) );

    $html .= $ef->DataEntryLine( "User Name", "%s", "text", "username",
              array( "size" => 20, "title" => "The name this user can log into the system with."), $this->prefix );
    if ( $ef->EditMode && $this->AllowedTo('ChangePassword') ) {
      $this->Set('new_password','******');
      unset($_POST['new_password']);
      $html .= $ef->DataEntryLine( "New Password", "%s", "password", "new_password",
                array( "size" => 20, "title" => "The user's password for logging in."), $this->prefix );
      $this->Set('confirm_password', '******');
      unset($_POST['confirm_password']);
      $html .= $ef->DataEntryLine( "Confirm", "%s", "password", "confirm_password",
                array( "size" => 20, "title" => "Confirm the new password."), $this->prefix );
    }

    $html .= $ef->DataEntryLine( "Full Name", "%s", "text", "fullname",
              array( "size" => 50, "title" => "The user's full name."), $this->prefix );

    $html .= $ef->DataEntryLine( "Email", "%s", "text", "email",
              array( "size" => 50, "title" => "The user's e-mail address."), $this->prefix );

    $html .= $ef->DataEntryLine( "Active", ($this->Get('active') == 't'? 'Yes' : 'No'), "checkbox", "active",
              array( "_label" => "User is active",
                     "title" => "Is this user active?."), $this->prefix );

    $html .= $ef->DataEntryLine( "EMail OK", $session->FormattedDate($this->Values->email_ok,'timestamp'), "timestamp", "email_ok",
              array( "title" => "When the user's e-mail account was validated."), $this->prefix );

    $html .= $ef->DataEntryLine( "Joined", $session->FormattedDate($this->Get('joined'),'timestamp') );
    $html .= $ef->DataEntryLine( "Updated", $session->FormattedDate($this->Get('updated'),'timestamp') );
    $html .= $ef->DataEntryLine( "Last used", $session->FormattedDate($this->Get('last_used'),'timestamp') );

    return $html;
  }


  /**
  * Render the user's administrative roles
  *
  * @return string The string of html to be output
  */
  function RenderRoles( $ef, $title = "User Roles" ) {
    global $session;
    $html = "";

    $html = ( $title == "" ? "" : $ef->BreakLine($title) );

    $html .= '<tr><th class="prompt">User Roles</th><td class="entry">';
    if ( $ef->EditMode ) {
      $sql = "SELECT role_name FROM roles ";
      if ( ! ($session->AllowedTo('Admin') ) ) {
        $sql .= "NATURAL JOIN role_member WHERE user_no=$session->user_no ";
      }
      $sql .= "ORDER BY roles.role_no";

      $ef->record->roles = array();

      // Select the records
      $q = new PgQuery($sql, $user_no, $user_no);
      if ( $q && $q->Exec("User") && $q->rows ) {
        $i=0;
        while( $row = $q->Fetch() ) {
          dbg_error_log("User", ":RenderRoles: Is a member of '%s': %s", $row->role_name, $this->roles[$row->role_name] );
          $ef->record->roles[$row->role_name] = $this->roles[$row->role_name];
          $html .= $ef->DataEntryField( "", "checkbox", "roles[$row->role_name]",
                          array("title" => "Does the user have the right to perform this role?",
                                    "_label" => "$row->role_name" ) );
        }
      }
    }
    else {
      foreach( $this->roles AS $k => $v ) {
        if ( $i++ > 0 ) $html .= ", ";
        $html .= $k;
      }
    }
    $html .= '</td></tr>'."\n";

    return $html;
  }

  /**
  * Validate the information the user submitted
  * @return boolean Whether the form data validated OK.
  */
  function Validate( ) {
    global $session, $c;
    dbg_error_log("User", ":Validate: Validating user");

    $valid = true;

    if ( $this->Get('fullname') == "" ) {
      $c->messages[] = "ERROR: The full name may not be blank.";
      $valid = false;
    }

    // Password changing is a little special...
    if ( $_POST['new_password'] != "******" && $_POST['new_password'] != ""  ) {
      if ( $_POST['new_password'] == $_POST['confirm_password'] ) {
        $this->Set('password',$_POST['new_password']);
      }
      else {
        $c->messages[] = "ERROR: The new password must match the confirmed password.";
        $valid = false;
      }
    }

    dbg_error_log("User", ":Validate: User %s validation", ($valid ? "passed" : "failed"));
    return $valid;
  }

  /**
  * Write the User record.
  * @return Success.
  */
  function Write() {
    global $c;
    if ( parent::Write() ) {
      $c->messages[] = "User record written.";
      if ( $this->WriteType == 'insert' ) {
        $qry = new PgQuery( "SELECT currval('usr_user_no_seq');" );
        $qry->Exec("User::Write");
        $sequence_value = $qry->Fetch(true);  // Fetch as an array
        $this->user_no = $sequence_value[0];
      }
      return true;
    }
    return false;
  }

}
?>