<?php
/**
 * Plugin Name:         eQual - Auth
 * Plugin URI:          https://github.com/equalpress
 * Description:         A plugin for connecting to the eQual framework
 * Author:              eQualPress
 * Original Author(s):  AlexisVS, Cédric Françoys
 * Author URI:          https://github.com/equalpress
 * Text Domain:         eq-auth
 * Domain Path:         /languages
 * Version:             0.1.1
 * Requires at least:   0.1.0
 * Requires:            eq-run
 *
 * @package         Eq_Auth
 */


use core\Group;
use equal\auth\AuthenticationManager;

add_action('wp_login', 'eq_auth_wp_login', 10, 2);
/**
 * @throws Exception
 */
function eq_auth_wp_login(string $user_login, WP_User $user): void {
    load_eQual_lib();

    $auth = eQual::inject(['auth']);

    /** @var AuthenticationManager $auth */
    $auth = $auth['auth'];

    $eq_user = \wordpress\User::search(['wordpress_user_id', '=', $user->ID])
        ->read([
            'id',
            'groups_ids'
        ])
        ->first(true);

    if ($eq_user) {
        $eq_groups = Group::search(['id', 'in', $eq_user['groups_ids']])->read(['name'])->get(true);

        $eq_user['groups'] = array_values(array_map(function ($group) {
                return $group['name'];
            }, $eq_groups));

        $access_token = $auth->token($eq_user['id'], constant('AUTH_ACCESS_TOKEN_VALIDITY'));

        $auth->su($eq_user['id']);

        setcookie('access_token', $access_token, [
            'expires'  => time() + constant('AUTH_ACCESS_TOKEN_VALIDITY'),
            'httponly' => true,
            'secure'   => constant('AUTH_TOKEN_HTTPS'),
        ]);

        if (in_array('admins', $eq_user['groups'])) {
            // Redirect to the WordPress admin dashboard
            wp_redirect(admin_url());
            exit();
        }
    }

    // Redirect to the WordPress home page
    // wp_redirect(home_url());
}

add_action('user_register', 'eq_auth_user_registered');
/**
 * @throws Exception
 */
function eq_auth_user_registered(int $user_id): void {
    load_eQual_lib();

    $wpUser = get_userdata($user_id);

    $eq_user = \wordpress\User::search(['wordpress_user_id', '=', $wpUser->ID])
        ->read([
            'id',
            'groups_ids'
        ])
        ->first(true);

    if (empty($eq_user)) {
        $username = explode('@', $wpUser->user_email)[0];
        $password = wp_generate_password();

        $user_data = [
            'wordpress_user_id' => $user_id,
            'email'             => $wpUser->user_email,
            'username'          => $username,
            'password'          => $password,
        ];

        \config\eQual::run('do', 'wordpress_user_signup', $user_data);
    }
}

add_action('password_reset', 'eq_auth_password_reset', 10, 2);
/**
 * @throws Exception
 */
function eq_auth_password_reset(WP_User $user, string $new_pass): void {
    load_eQual_lib();

    $eqUser = \wordpress\User::search(['login', '=', $user->user_email])->read(['id']);

    if ($eqUser) {
        $eqUser->update(['password' => password_hash($new_pass, PASSWORD_BCRYPT)]);
    }
}

add_action('profile_update', 'eq_auth_profile_updated');
function eq_auth_profile_updated(int $user_id): void {
    $wpUser = get_userdata($user_id);

    if ($wpUser instanceof WP_User) {
        load_eQual_lib();

        $wpUser = $wpUser->to_array();

        $eq_user = \wordpress\User::search(['wordpress_user_id', '=', $user_id])->read([
            'id',
            'wordpress_user_id'
        ])->first(true);

        if ((empty($wpUser) || !empty($wpUser['user_activation_key'])) && !$eq_user) {
            return;
        }

        $wpUser['firstname'] = get_user_meta($user_id, 'first_name', true);
        $wpUser['lastname'] = get_user_meta($user_id, 'last_name', true);
        $email = mb_split('@', $wpUser['user_email'])[0];

        $user_data = [
            'firstname' => $wpUser['firstname'],
            'lastname'  => $wpUser['lastname'],
            'login'     => $wpUser['user_email'],
            'username'  => $email
        ];

        if (!empty($eq_user['wordpress_user_id'])) {
            eQual::run('do', 'wordpress_user_update', [
                'id'        => (int)$eq_user['id'],
                'fields'    => $user_data,
                'update_wp' => '0'
            ]);
        }
    }
}

add_action('wp_logout', function (int $user_id): void {
    setcookie('access_token', '', time());
});
