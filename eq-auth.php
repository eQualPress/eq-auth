<?php
/**
 * Plugin Name:     eq-auth
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
use core\User;
use equal\auth\AuthenticationManager;

include_once ABSPATH . '/wp-content/Log.php';


add_action( 'wp_login', 'eq_auth_wp_login', 10, 2 );
/**
 * @throws Exception
 */
function eq_auth_wp_login( string $user_login, WP_User $user ): void {

    $auth = eQual::inject( [ 'auth' ] );

    /** @var AuthenticationManager $auth */
    $auth = $auth['auth'];

    $eqUser = \wordpress\User::search( [ 'login', '=', $user->user_email ] )->read( [
        'id',
        'groups_ids'
    ] )->first( true );

    \wpcontent\Log::report( 'eq_auth_wp_login => WP_USER', [
        'user'       => $user,
        'user_email' => $user->user_email
    ] );
    \wpcontent\Log::report( 'eq_auth_wp_login => $user_login', $user_login );
    \wpcontent\Log::report( 'eq_auth_wp_login => $eqUser', $eqUser );

    if ( ! $eqUser ) {
        throw new Exception( "user_not_found", QN_ERROR_INVALID_USER );
    }

    $eqGroups = Group::search( [ 'id', 'in', $eqUser['groups_ids'] ] )->read( [ 'name' ] )->get( true );
    \wpcontent\Log::report( 'eq_auth_wp_login => eqGroups', $eqGroups );

    $eqUser['groups'] = array_values( array_map( function ( $group ) {
        return $group['name'];
    }, $eqGroups ) );

    if ( in_array( 'users', $eqUser['groups'] ) ) {
        $access_token = $auth->token( $eqUser['id'], constant( 'AUTH_ACCESS_TOKEN_VALIDITY' ) );

        $auth->su( $eqUser['id'] );

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

    \wpcontent\Log::report( 'eq_auth_user_registered => $wpUser', [
        'user_id'  => $user_id,
        'userdata' => $wpUser
    ] );

    $eqUser = \wordpress\User::search( [ 'login', '=', $wpUser->user_email ] )->read( [
        'id',
        'groups_ids'
    ] )->first( true );

    \wpcontent\Log::report( 'eq_auth_user_registered => $_POST', $_POST );
    \wpcontent\Log::report( 'eq_auth_user_registered => $eqUser', $eqUser );
    \wpcontent\Log::report( 'eq_auth_user_registered => ! $eqUser', ( ! $eqUser ) );

    if ( empty( $eqUser ) ) {
        $username = explode( '@', $wpUser->user_email )[0];
        $password = wp_generate_password();

        $userData = [
            'wordpress_user_id' => $user_id,
            'email'             => $wpUser->user_email,
            'username'          => $username,
            'password'          => $password,
        ];

        \wpcontent\Log::report( 'eq_auth_user_registered => $userData', $userData );

        $eqWordpressUserSigninResponse = \config\eQual::run( 'do', 'wordpress_user_signup', $userData );

        \wpcontent\Log::report( 'eq_auth_user_registered => $eqWordpressUserSigninResponse', $eqWordpressUserSigninResponse );
    }
}

add_action( 'password_reset', 'eq_auth_password_reset', 10, 2 );
function eq_auth_password_reset( WP_User $user, string $new_pass ): void {
    $eqUser = \wordpress\User::search( [ 'login', '=', $user->user_email ] )->read( [ 'id' ] );

    \wpcontent\Log::report( 'eq_auth_password_reset => $eqUser', $eqUser );

    if ( $eqUser ) {
        $eqUser->update( [ 'password' => $new_pass ] );
    }
}

add_action( 'profile_update', 'eq_auth_profile_updated', 10, 2 );
function eq_auth_profile_updated( int $user_id, WP_User $old_user_data, array $userdata ): void {
    $eqUser = \wordpress\User::search( [ 'login', '=', $userdata['user_email'] ] )->read( [ 'id' ] );

    \wpcontent\Log::report( 'eq_auth_profile_updated => $eqUser', $eqUser );

    if ( $eqUser ) {
        eQual::run( 'do', 'wordpress_user_update', [
            'id'        => $eqUser['id'],
            'fields'    => $userdata,
            'update_wp' => false
        ] );
    }
}

add_action( 'wp_logout', 'eq_auth_wp_logout' );
function eq_auth_wp_logout( int $user_id ): void {
    setcookie( 'access_token', '', time() );
}
