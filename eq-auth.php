<?php
/**
 * Plugin Name:     eQual - Auth
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     A plugin for connecting to the eQual framework
 * Author:          AlexisVS
 * Author URI:      https://github.com/AlexisVS
 * Text Domain:     eq-auth
 * Domain Path:     /languages
 * Version:         0.1.0
 * Requires at least: 0.1.0
 * Requires: eq-run
 *
 * @package         Eq_Auth
 */


use core\Group;
use equal\auth\AuthenticationManager;

/*
include_once ABSPATH . '/wp-content/Log.php';
*/

add_action( 'wp_login', 'eq_auth_wp_login', 10, 2 );
/**
 * @throws Exception
 */
function eq_auth_wp_login( string $user_login, WP_User $user ): void {

    $auth = eQual::inject( [ 'auth' ] );

    /** @var AuthenticationManager $auth */
    $auth = $auth['auth'];

    $eq_user = \wordpress\User::search( [ 'login', '=', $user->user_email ] )->read( [
        'id',
        'groups_ids'
    ] )->first( true );

    /*
    \wpcontent\Log::report( 'eq_auth_wp_login => WP_USER', [
        'user'       => $user,
        'user_email' => $user->user_email
    ] );
    \wpcontent\Log::report( 'eq_auth_wp_login => $user_login', $user_login );
    \wpcontent\Log::report( 'eq_auth_wp_login => $eqUser', $eq_user );
    */
    
    if ( ! $eq_user ) {
        throw new Exception( "user_not_found", QN_ERROR_INVALID_USER );
    }

    $eq_groups = Group::search( [ 'id', 'in', $eq_user['groups_ids'] ] )->read( [ 'name' ] )->get( true );

    /*
    \wpcontent\Log::report( 'eq_auth_wp_login => eqGroups', $eq_groups );
    */
    
    $eq_user['groups'] = array_values( array_map( function ( $group ) {
        return $group['name'];
    }, $eq_groups ) );

    if ( in_array( 'users', $eq_user['groups'] ) ) {
        $access_token = $auth->token( $eq_user['id'], constant( 'AUTH_ACCESS_TOKEN_VALIDITY' ) );

        $auth->su( $eq_user['id'] );

        setcookie(
            'access_token',
            $access_token,
            [
                'expires'  => time() + constant( 'AUTH_ACCESS_TOKEN_VALIDITY' ),
                'httponly' => true,
                'secure'   => constant( 'AUTH_TOKEN_HTTPS' ),
            ]
        );
    }
}

add_action( 'user_register', 'eq_auth_user_registered' );
function eq_auth_user_registered( int $user_id ): void {

    $wpUser = get_userdata( $user_id );

    /*
    \wpcontent\Log::report( 'eq_auth_user_registered => $wpUser', [
        'user_id'  => $user_id,
        'userdata' => $wpUser
    ] );
    */
    
    $eq_user = \wordpress\User::search( [ 'login', '=', $wpUser->user_email ] )->read( [
        'id',
        'groups_ids'
    ] )->first( true );

    /*
    \wpcontent\Log::report( 'eq_auth_user_registered => $_POST', $_POST );
    \wpcontent\Log::report( 'eq_auth_user_registered => $eqUser', $eq_user );
    \wpcontent\Log::report( 'eq_auth_user_registered => ! $eqUser', ( ! $eq_user ) );
    */
    
    if ( empty( $eq_user ) ) {
        $username = explode( '@', $wpUser->user_email )[0];
        $password = wp_generate_password();

        $user_data = [
            'wordpress_user_id' => $user_id,
            'email'             => $wpUser->user_email,
            'username'          => $username,
            'password'          => $password,
        ];

        /*
        \wpcontent\Log::report( 'eq_auth_user_registered => $userData', $user_data );
        */
        
        $eq_wordpress_user_signin_response = \config\eQual::run( 'do', 'wordpress_user_signup', $user_data );

        /*
        \wpcontent\Log::report( 'eq_auth_user_registered => $eqWordpressUserSigninResponse', $eq_wordpress_user_signin_response );
        */
    }
}

add_action( 'password_reset', 'eq_auth_password_reset', 10, 2 );
function eq_auth_password_reset( WP_User $user, string $new_pass ): void {
    $eqUser = \wordpress\User::search( [ 'login', '=', $user->user_email ] )->read( [ 'id' ] );

    /*
    \wpcontent\Log::report( 'eq_auth_password_reset => $eqUser', $eqUser );
    */
    
    if ( $eqUser ) {
        $eqUser->update( [ 'password' => password_hash( $new_pass, PASSWORD_BCRYPT ) ] );
    }
}

add_action( 'profile_update', 'eq_auth_profile_updated' );
function eq_auth_profile_updated( int $user_id ): void {
    $wpUser = get_userdata( $user_id );

    if ( $wpUser instanceof WP_User ) {
        $wpUser = $wpUser->to_array();

        $eq_user = \wordpress\User::search( [ 'wordpress_user_id', '=', $user_id ] )->read( [
            'id',
            'wordpress_user_id'
        ] )->first( true );

        /*
        \wpcontent\Log::report( 'eq_auth_profile_updated => $eqUser', $eq_user );
        \wpcontent\Log::report( 'eq_auth_profile_updated => $wpUser array', $wpUser );
        */
        
        if ( ( empty( $wpUser ) || ! empty( $wpUser['user_activation_key'] ) ) && ! $eq_user ) {
            return;
        }

        $wpUser['firstname'] = get_user_meta( $user_id, 'first_name', true );
        $wpUser['lastname']  = get_user_meta( $user_id, 'last_name', true );
        $email               = mb_split( '@', $wpUser['user_email'] )[0];

        $user_data = [
            'firstname' => $wpUser['firstname'],
            'lastname'  => $wpUser['lastname'],
            'login'     => $wpUser['user_email'],
            'username'  => $email
        ];

        /*
        \wpcontent\Log::report( 'eq_auth_profile_updated => $wpUser with meta', $wpUser );
        */
        
        if ( ! empty( $eq_user['wordpress_user_id'] ) ) {
            eQual::run( 'do', 'wordpress_user_update', [
                'id'        => (int) $eq_user['id'],
                'fields'    => $user_data,
                'update_wp' => '0'
            ] );
        }
    }
}

add_action( 'wp_logout', 'eq_auth_wp_logout' );
function eq_auth_wp_logout( int $user_id ): void {
    setcookie( 'access_token', '', time() );
}
