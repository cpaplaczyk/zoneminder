<?php
//
// ZoneMinder auth library, $Date$, $Revision$
// Copyright (C) 2001-2008 Philip Coombes
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
//
//
require_once('session.php');

function userLogin($username='', $password='', $passwordHashed=false) {
  global $user;

  if ( !$username and isset($_REQUEST['username']) )
    $username = $_REQUEST['username'];
  if ( !$password and isset($_REQUEST['password']) )
    $password = $_REQUEST['password'];

  // if true, a popup will display after login
  // PP - lets validate reCaptcha if it exists
  if ( defined('ZM_OPT_USE_GOOG_RECAPTCHA')
      && defined('ZM_OPT_GOOG_RECAPTCHA_SECRETKEY')
      && defined('ZM_OPT_GOOG_RECAPTCHA_SITEKEY')
      && ZM_OPT_USE_GOOG_RECAPTCHA
      && ZM_OPT_GOOG_RECAPTCHA_SECRETKEY
      && ZM_OPT_GOOG_RECAPTCHA_SITEKEY )
  {
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $fields = array (
        'secret'    => ZM_OPT_GOOG_RECAPTCHA_SECRETKEY,
        'response'  => $_REQUEST['g-recaptcha-response'],
        'remoteip'  => $_SERVER['REMOTE_ADDR']
        );
    $res = do_post_request($url, http_build_query($fields));
    $responseData = json_decode($res,true);
    // PP - credit: https://github.com/google/recaptcha/blob/master/src/ReCaptcha/Response.php
    // if recaptcha resulted in error, we might have to deny login
    if ( isset($responseData['success']) && $responseData['success'] == false ) {
      // PP - before we deny auth, let's make sure the error was not 'invalid secret'
      // because that means the user did not configure the secret key correctly
      // in this case, we prefer to let him login in and display a message to correct
      // the key. Unfortunately, there is no way to check for invalid site key in code
      // as it produces the same error as when you don't answer a recaptcha
      if ( isset($responseData['error-codes']) && is_array($responseData['error-codes']) ) {
        if ( !in_array('invalid-input-secret',$responseData['error-codes']) ) {
          Error('reCaptcha authentication failed');
          return null;
        } else {
          Error('Invalid recaptcha secret detected');
        }
      }
    } // end if success==false
  } // end if using reCaptcha

  $sql = 'SELECT * FROM Users WHERE Enabled=1';
  $sql_values = NULL;
  if ( ZM_AUTH_TYPE == 'builtin' ) {
    if ( $passwordHashed ) {
      $sql .= ' AND Username=? AND Password=?';
    } else {
      $sql .= ' AND Username=? AND Password=password(?)';
    }
    $sql_values = array($username, $password);
  } else {
    $sql .= ' AND Username=?';
    $sql_values = array($username);
  }
  $close_session = 0;
  if ( !is_session_started() ) {
    session_start();
    $close_session = 1;
  }
  $_SESSION['remoteAddr'] = $_SERVER['REMOTE_ADDR']; // To help prevent session hijacking
  if ( $dbUser = dbFetchOne($sql, NULL, $sql_values) ) {
    ZM\Info("Login successful for user \"$username\"");
    $user = $dbUser;
    unset($_SESSION['loginFailed']);
    if ( ZM_AUTH_TYPE == 'builtin' ) {
      $_SESSION['passwordHash'] = $user['Password'];
    }
    $_SESSION['username'] = $user['Username'];
    if ( ZM_AUTH_RELAY == 'plain' ) {
      // Need to save this in session, can't use the value in User because it is hashed
      $_SESSION['password'] = $_REQUEST['password'];
    }
    zm_session_regenerate_id();
  } else {
    ZM\Warning("Login denied for user \"$username\"");
    $_SESSION['loginFailed'] = true;
    unset($user);
  }
  if ( $close_session )
    session_write_close();
  return isset($user) ? $user: null;
} # end function userLogin

function userLogout() {
  global $user;
  ZM\Info('User "'.$user['Username'].'" logged out');
  unset($user);
  zm_session_clear();
}

function getAuthUser($auth) {
  if ( ZM_OPT_USE_AUTH && ZM_AUTH_RELAY == 'hashed' && !empty($auth) ) {
    $remoteAddr = '';
    if ( ZM_AUTH_HASH_IPS ) {
      $remoteAddr = $_SERVER['REMOTE_ADDR'];
      if ( !$remoteAddr ) {
        ZM\Error("Can't determine remote address for authentication, using empty string");
        $remoteAddr = '';
      }
    }

    $values = array();
    if ( isset($_SESSION['username']) ) {
      # Most of the time we will be logged in already and the session will have our username, so we can significantly speed up our hash testing by only looking at our user.
      # Only really important if you have a lot of users.
      $sql = 'SELECT * FROM Users WHERE Enabled = 1 AND Username=?';
      array_push($values, $_SESSION['username']);
    } else {
      $sql = 'SELECT * FROM Users WHERE Enabled = 1';
    }

    foreach ( dbFetchAll($sql, NULL, $values) as $user ) {
      $now = time();
      for ( $i = 0; $i < ZM_AUTH_HASH_TTL; $i++, $now -= ZM_AUTH_HASH_TTL * 1800 ) { // Try for last two hours
        $time = localtime($now);
        $authKey = ZM_AUTH_HASH_SECRET.$user['Username'].$user['Password'].$remoteAddr.$time[2].$time[3].$time[4].$time[5];
        $authHash = md5($authKey);

        if ( $auth == $authHash ) {
          return $user;
        }
      } // end foreach hour
    } // end foreach user
  } // end if using auth hash
  ZM\Error("Unable to authenticate user from auth hash '$auth'");
  return false;
} // end getAuthUser($auth)

function generateAuthHash($useRemoteAddr, $force=false) {
  if ( ZM_OPT_USE_AUTH and ZM_AUTH_RELAY == 'hashed' and isset($_SESSION['username']) and $_SESSION['passwordHash'] ) {
    # regenerate a hash at half the liftetime of a hash, an hour is 3600 so half is 1800
    $time = time();
    $mintime = $time - ( ZM_AUTH_HASH_TTL * 1800 );

    if ( $force or ( !isset($_SESSION['AuthHash'.$_SESSION['remoteAddr']]) ) or ( $_SESSION['AuthHashGeneratedAt'] < $mintime ) ) {
      # Don't both regenerating Auth Hash if an hour hasn't gone by yet
      $local_time = localtime();
      $authKey = '';
      if ( $useRemoteAddr ) {
        $authKey = ZM_AUTH_HASH_SECRET.$_SESSION['username'].$_SESSION['passwordHash'].$_SESSION['remoteAddr'].$local_time[2].$local_time[3].$local_time[4].$local_time[5];
      } else {
        $authKey = ZM_AUTH_HASH_SECRET.$_SESSION['username'].$_SESSION['passwordHash'].$local_time[2].$local_time[3].$local_time[4].$local_time[5];
      }
      #ZM\Logger::Debug("Generated using hour:".$local_time[2] . ' mday:' . $local_time[3] . ' month:'.$local_time[4] . ' year: ' . $local_time[5] );
      $auth = md5($authKey);
      if ( !$force ) {
        $close_session = 0;
        if ( !is_session_started() ) {
          session_start();
          $close_session = 1;
        }
        $_SESSION['AuthHash'.$_SESSION['remoteAddr']] = $auth;
        $_SESSION['AuthHashGeneratedAt'] = $time;
        session_write_close();
      } else {
        return $auth;
      }
      #ZM\Logger::Debug("Generated new auth $auth at " . $_SESSION['AuthHashGeneratedAt']. " using $authKey" );
      #} else {
      #ZM\Logger::Debug("Using cached auth " . $_SESSION['AuthHash'] ." beacuse generatedat:" . $_SESSION['AuthHashGeneratedAt'] . ' < now:'. $time . ' - ' .  ZM_AUTH_HASH_TTL . ' * 1800 = '. $mintime);
    } # end if AuthHash is not cached
    return $_SESSION['AuthHash'.$_SESSION['remoteAddr']];
  } # end if using AUTH and AUTH_RELAY
  return '';
}

function visibleMonitor($mid) {
  global $user;

  return ( empty($user['MonitorIds']) || in_array($mid, explode(',', $user['MonitorIds'])) );
}

function canView($area, $mid=false) {
  global $user;

  return ( ($user[$area] == 'View' || $user[$area] == 'Edit') && ( !$mid || visibleMonitor($mid) ) );
}

function canEdit($area, $mid=false) {
  global $user;

  return ( $user[$area] == 'Edit' && ( !$mid || visibleMonitor($mid) ));
}

global $user;
if ( ZM_OPT_USE_AUTH ) {
  $close_session = 0;
  if ( !is_session_started() ) {
    session_start();
    $close_session = 1;
  }

  if ( isset($_SESSION['username']) ) {
    # Need to refresh permissions and validate that the user still exists
    $sql = 'SELECT * FROM Users WHERE Enabled=1 AND Username=?';
    $user = dbFetchOne($sql, NULL, array($_SESSION['username']));
  }

  if ( ZM_AUTH_RELAY == 'plain' ) {
    // Need to save this in session
    $_SESSION['password'] = $password;
  }
  $_SESSION['remoteAddr'] = $_SERVER['REMOTE_ADDR']; // To help prevent session hijacking

  if ( ZM_AUTH_HASH_LOGINS && empty($user) && !empty($_REQUEST['auth']) ) {
    if ( $authUser = getAuthUser($_REQUEST['auth']) ) {
      userLogin($authUser['Username'], $authUser['Password'], true);
    }
  } else if ( isset($_REQUEST['username']) and isset($_REQUEST['password']) ) {
    userLogin($_REQUEST['username'], $_REQUEST['password'], false);
  }
  if ( !empty($user) ) {
    // generate it once here, while session is open.  Value will be cached in session and return when called later on
    generateAuthHash(ZM_AUTH_HASH_IPS);
  }
  if ( $close_session )
    session_write_close();
} else {
  $user = $defaultUser;
}
?>
