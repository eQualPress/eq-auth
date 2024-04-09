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

add_action( 'wp_login', 'eq_auth_wp_login', 10, 2 );
/**
 * @throws Exception
 */
function eq_auth_wp_login( string $user_login, WP_User $user ): void {

    $auth = eQual::inject( [ 'auth' ] );
    $auth = $auth['auth'];

    $eqUser = User::search( [ 'login', '=', $user_login ] )->read( [ 'id', 'groups_ids' ] )->first( true );

    if ( ! $eqUser ) {
        throw new Exception( "user_not_found", QN_ERROR_INVALID_USER );
    }

    $eqGroups = Group::search( [ 'id', 'in', $eqUser['groups_ids'] ] )->read( [ 'name' ] )->get( true );

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
/**
 * @throws Exception
 */
function eq_auth_user_registered( int $user_id, array $userdata ): void {
    $eqUser = User::id( $userdata['user_email'] )->read( [ 'id', 'groups_ids' ] )->first( true );

    if ( ! $eqUser ) {
        \config\eQual::run( 'do', 'wordpress_user_signin', [
            'email'             => $userdata['user_email'],
            'username'          => $userdata['nickname'],
            'password'          => $userdata['user_pass'],
            'firstname'         => $userdata['first_name'],
            'lastname'          => $userdata['last_name'],
            'wordpress_user_id' => $user_id,
            'send_confirm'      => false
        ] );
    }

    eq_auth_wp_login( $userdata['user_email'], get_user_by( 'id', $user_id ) );
}

add_action( 'wp_logout', 'eq_auth_wp_logout' );
function eq_auth_wp_logout( int $user_id ): void {
    setcookie( 'access_token', '', time() );
}
