<?php
/**
 * tric cli functions.
 */

namespace Tribe\Test;

/**
 * Checks a specified target exists in the `dev/_plugins` directory.
 *
 * @param string $target The target to check in the `dev/_plugins` directory.
 */
function ensure_dev_plugin( $target ) {
	$targets     = array_keys( dev_plugins() );
	$targets_str = implode( PHP_EOL, array_map( static function ( $target ) {
		return "  - {$target}";
	}, $targets ) );

	if ( false === $target ) {
		echo magenta( "This command needs a target argument; available targets are:\n${targets_str}\n" );
		exit( 1 );
	}

	if ( ! in_array( $target, $targets, true ) ) {
		echo magenta( "'{$target}' is not a valid target; available targets are:\n${targets_str}\n" );
		exit( 1 );
	}
}

/**
 * Sets up the environment form the cli tool.
 *
 * @param string $root_dir The cli tool root directory.
 */
function setup_tric_env( $root_dir ) {
	// Let's declare we're performing trics.
	putenv( 'TRIBE_TRIC=1' );

	$os = os();
	if ( $os === 'macOS' || $os === 'Windows' ) {
		// Do not fix file modes on hosts that implement user ID and group ID remapping at the Docker daemon level.
		putenv( 'FIXUID=0' );
	}

	// Load the distribution version configuration file, the version-controlled one.
	load_env_file( $root_dir . '/.env.tric' );

	// Load the local overrides, this file is not version controlled.
	if ( file_exists( $root_dir . '/.env.tric.local' ) ) {
		load_env_file( $root_dir . '/.env.tric.local' );
	}

	// Load the current session configuration file.
	if ( file_exists( $root_dir . '/.env.tric.run' ) ) {
		load_env_file( $root_dir . '/.env.tric.run' );
	}

	// Most commands are nested shells that should not run with a time limit.
	remove_time_limit();
}

/**
 * Returns the current `use` target.
 *
 * @param bool $require Whether to require a target, and fail if not set, or not.
 *
 * @return string|string Either the current target or `false` if the target is not set. If `$require` is `true` then the
 *                       return value will always be a non empty string.
 */
function tric_target( $require = true ) {
	$using = getenv( 'TRIC_CURRENT_PROJECT' );
	if ( $require ) {
		return $using;
	}
	if ( empty( $using ) ) {
		echo magenta( "Use target not set; use the 'use' sub-command to set it.\n" );
		exit( 1 );
	}

	return trim( $using );
}

/**
 * Switches the current `use` target.
 *
 * @param string $target Target to switch to.
 */
function tric_switch_target( $target ) {
	$root              = dirname( dirname( __DIR__ ) );
	$run_settings_file = "{$root}/.env.tric.run";

	write_env_file( $run_settings_file, [ 'TRIC_CURRENT_PROJECT' => $target ], true );

	setup_tric_env( $root );
}

/**
 * Returns a map of the stack PHP services that relates the service to its pretty name.
 *
 * @return array<string,string> A map of the stack PHP services relating each service to its pretty name.
 */
function php_services() {
	return [
		'wordpress'   => 'WordPress',
		'codeception' => 'Codeception',
	];
}

/**
 * Restart the stack PHP services.
 */
function restart_php_services() {
	foreach ( php_services() as $service => $pretty_name ) {
		restart_service( $service, $pretty_name );
	}
}

/**
 * Restarts a stack services if it's running.
 *
 * @param string      $service     The name of the service to restart, e.g. `wordpress`.
 * @param string|null $pretty_name The pretty name to use for the service, or `null` to use the service name.
 */
function restart_service( $service, $pretty_name = null ) {
	$pretty_name   = $pretty_name ?: $service;
	$tric          = docker_compose( [ '-f', stack() ] );
	$tric_realtime = docker_compose_realtime( [ '-f', stack() ] );

	$service_running = $tric( [ 'ps', '-q', $service ] )( 'string_output' );
	if ( ! empty( $service_running ) ) {
		echo colorize( "Restarting {$pretty_name} service...\n" );
		$tric_realtime( [ 'restart', $service ] );
		echo colorize( "<light_cyan>{$pretty_name} service restarted.</light_cyan>\n" );
	} else {
		echo colorize( "{$pretty_name} service was not running.\n" );
	}
}

/**
 * Returns the absolute path to the current plugins directory tric is using.
 *
 * @param string $path An optional path to append to the current tric plugin directory.
 *
 * @return string The absolute path to the current plugins directory tric is using.
 *
 */
function tric_plugins_dir( $path = '' ) {
	$plugins_dir = getenv( 'TRIC_PLUGINS_DIR' );
	$dev_dir     = dev();

	if ( empty( $plugins_dir ) ) {
		// Use the default `dev/_plugins` directory in tric repository.
		$dir = $dev_dir . '/_plugins';
	} elseif ( is_dir( $plugins_dir ) ) {
		// Use the specified directory.
		$dir = $plugins_dir;
	} else {
		if ( 0 === strpos( $plugins_dir, '.' ) ) {
			// Resolve the './...' paths a relative to the `dev` directory in tric repository.
			$dir = preg_replace( '/^\\./', $dev_dir, $plugins_dir );
		} else {
			// Use a directory relative to the `dev` directory in tric reopository.
			$dir = $dev_dir . '/' . ltrim( $plugins_dir, '\\/' );
		}
	}

	return empty( $path ) ? $dir : $dir . '/' . ltrim( $path, '\\/' );
}

/**
 * Clones a company plugin in the current plugin root directory.
 *
 * @param string $plugin The plugin name, e.g. `the-events-calendar` or `event-tickets`.
 */
function clone_plugin( $plugin ) {
	$plugin_dir  = tric_plugins_dir();
	$plugin_path = tric_plugins_dir( $plugin );

	if ( ! file_exists( $plugin_dir ) ) {
		echo "Creating the plugins directory...\n";
		if ( ! mkdir( $plugin_dir ) && ! is_dir( $plugin_dir ) ) {
			echo magenta( "Could not create {$plugin_dir} directory; please check the parent directory is writeable." );
			exit( 1 );
		}
	}

	// If the plugin path already exists, don't bother cloning.
	if ( file_exists( $plugin_path ) ) {
		return;
	}

	echo "Cloning {$plugin}...\n";

	$repository = github_company_handle() . '/' . escapeshellcmd( $plugin );

	$clone_status = process_realtime(
		'git clone --recursive git@github.com:' . $repository . '.git ' . escapeshellcmd( $plugin_path )
	);

	if ( 0 !== $clone_status ) {
		echo magenta( "Could not clone the {$repository} repository; please check your access rights to the repository." );
		exit( 1 );
	}
}

/**
 * Sets up the files required to run tests in the plugin using tric stack.
 *
 * @param string $plugin The plugin name, e.g. 'the-events-calendar` or `event-tickets`.
 */
function setup_plugin_tests( $plugin ) {
	$plugin_path    = tric_plugins_dir() . '/' . $plugin;
	$relative_paths = [ '' ];

	if ( file_exists( "{$plugin_path}/common" ) ) {
		$relative_paths[] = 'common';
	}

	foreach ( $relative_paths as $relative_path ) {
		$target_path   = "{$plugin_path}/{$relative_path}";
		$relative_path = empty( $relative_path ) ? '' : "{$relative_path}/";

		write_tric_test_config( $target_path );
		echo colorize( "Created/updated <light_cyan>{$relative_path}test-config.tric.php</light_cyan> " .
		               "in {$plugin}.\n" );

		write_tric_env_file( $target_path );
		echo colorize( "Created/updated <light_cyan>{$relative_path}.env.testing.tric</light_cyan> " .
		               "in {$plugin}.\n" );


		if ( write_codeception_config( $target_path ) ) {
			echo colorize( "Created <light_cyan>{$relative_path}codeception.yml</light_cyan> in " .
			               "<light_cyan>{$plugin}</light_cyan>.\n" );
		} else {
			echo colorize( "Skipped creating <light_cyan>{$relative_path}codeception.yml</light_cyan>" .
			               " in <light_cyan>{$plugin}</light_cyan>. It already exists (*).\n" );
			echo colorize( "\n(*) A skipped codeception.yml file could be ok. If your tests fail to run, try removing the" .
			               " codeception.yml and running <light_cyan>tric init <plugin></light_cyan> again.\n\n" );
		}
	}
}

/**
 * Returns the handle (username) of the company to clone plugins from.
 *
 * Configured using the `TRIC_GITHUB_COMPANY_HANDLE` env variable.
 *
 * @return string The handle of the company to clone plugins from.
 */
function github_company_handle() {
	$handle = getenv( 'TRIC_GITHUB_COMPANY_HANDLE' );

	return ! empty( $handle ) ? trim( $handle ) : 'moderntribe';
}

/**
 * Runs a process in tric stack and returns the exit status.
 *
 * @return \Closure The process closure to start a real-time process using tric stack.
 */
function tric_realtime() {
	return docker_compose_realtime( [ '-f', stack() ] );
}

/**
 * Returns the process Closure to start a real-time process using tric stack.
 *
 * @return \Closure The process closure to start a real-time process using tric stack.
 */
function tric_process() {
	return docker_compose( [ '-f', stack() ] );
}

/**
 * Tears down tric stack.
 */
function teardown_stack() {
	tric_realtime()( [ 'down', '--volumes', '--remove-orphans' ] );
}

/**
 * Rebuilds the tric stack.
 */
function rebuild_stack() {
	echo "Building the stack images...\n\n";
	tric_realtime()( [ 'build' ] );
	echo light_cyan( "\nStack images built.\n\n" );
}

/**
 * Prints information about tric tool.
 */
function tric_info() {
	$config_vars = [
		'TRIC_TEST_SUBNET',
		'CLI_VERBOSITY',
		'TRIC_CURRENT_PROJECT',
		'TRIC_GITHUB_COMPANY_HANDLE',
		'TRIC_PLUGINS_DIR',
		'XDK',
		'XDE',
		'XDH',
		'XDP',
		'MYSQL_ROOT_PASSWORD',
		'WORDPRESS_HTTP_PORT',
	];

	echo colorize( "<yellow>Configuration read from the following files:</yellow>\n" );
	$tric_root = dirname( dirname( __DIR__ ) );
	echo implode( "\n", array_filter( [
			file_exists( $tric_root . '/.env.tric' ) ? "  - " . $tric_root . '/.env.tric' : null,
			file_exists( $tric_root . '/.env.tric.local' ) ? "  - " . $tric_root . '/.env.tric.local' : null,
			file_exists( $tric_root . '/.env.tric.run' ) ? "  - " . $tric_root . '/.env.tric.run' : null,
		] ) ) . "\n\n";

	echo colorize( "<yellow>Current configuration:</yellow>\n" );
	foreach ( $config_vars as $key ) {
		$value = print_r( getenv( $key ), true );

		if ( $key === 'TRIC_PLUGINS_DIR' && $value !== tric_plugins_dir() ) {
			// If the configuration is using a relative path, then expose the absolute path.
			$value .= ' => ' . tric_plugins_dir();
		}

		echo colorize( "  - <light_cyan>{$key}</light_cyan>: {$value}\n" );
	}
}

/**
 * Returns the absolute path to the WordPress Core directory currently used by tric.
 *
 * The function will not check for the directory existence as we might be using this function to get a path to create.
 *
 * @param string $path An optional, relative, path to append to the WordPress Core directory absolute path.
 *
 * @return string The absolute path to the WordPress Core directory currently used by tric.
 */
function tric_wp_dir( $path = '' ) {
	$default = dev( '/_wordpress' );

	$wp_dir = getenv( 'TRIC_WP_DIR' );

	if ( ! empty( $wp_dir ) ) {
		if ( ! is_dir( $wp_dir ) ) {
			// Relative path, resolve from `dev`.
			$wp_dir = dev( ltrim( preg_replace( '^\\./', '', $wp_dir ), '\\/' ) );
		}
	} else {
		$wp_dir = $default;
	}

	return empty( $path ) ? $wp_dir : $wp_dir . '/' . ltrim( $path, '\\/' );
}

/**
 * Prints the current XDebug status to screen.
 */
function xdebug_status() {
	$value = getenv( 'XDE' );
	echo 'XDebug status is: ' . ( $value ? light_cyan( 'on' ) : magenta( 'off' ) ) . PHP_EOL;
	echo 'Remote host: ' . light_cyan( getenv( 'XDH' ) ) . PHP_EOL;
	echo 'Remote port: ' . light_cyan( getenv( 'XDP' ) ) . PHP_EOL;
	echo 'WordPress IDE Key: ' . light_cyan( getenv( 'XDK' ) ) . PHP_EOL;
	echo 'Codeception IDE Key: ' . light_cyan( getenv( 'XDK' ) . '_cc' ) . PHP_EOL;
	echo colorize( PHP_EOL . "You can override these values in the <light_cyan>.env.tric.local" .
	               "</light_cyan> file or by using the " .
	               "<light_cyan>'xdebug (host|key|port) <value>'</light_cyan> command." ) . PHP_EOL;
	echo PHP_EOL . ( 'Ensure the following path mappings are set (host path => container path) in your IDE:' ) . PHP_EOL . PHP_EOL;
	echo colorize( "  - <light_cyan>" . tric_plugins_dir() . "</light_cyan> => <light_cyan>/plugins</light_cyan>" ) . PHP_EOL;
	echo colorize( "  - <light_cyan>" . tric_wp_dir() . "</light_cyan> => <light_cyan>/var/www/html</light_cyan>" );

	$default_mask = ( tric_wp_dir() === dev( '/_wordpress' ) ) + 2 * ( tric_plugins_dir() === dev( '/_plugins' ) );

	switch ( $default_mask ) {
		case 1:
			echo PHP_EOL . PHP_EOL;
			echo yellow( 'Note: tric is using the default WordPress directory and a different plugins directory: ' .
			             'set path mappings correctly and keep that in mind.' );
			break;
		case 2:
			echo PHP_EOL . PHP_EOL;
			echo yellow( 'Note: tric is using the default plugins directory and a different WordPress directory: ' .
			             'set path mappings correctly and keep that in mind.' );
			break;
		case 3;
		default:
			break;
	}
}

/**
 * Handles the XDebug command request.
 *
 * @param callable $args The closure that will produce the current XDebug request arguments.
 */
function tric_handle_xdebug( callable $args ) {
	$run_settings_file = dev( '/.env.tric.run' );
	$toggle            = $args( 'toggle', 'on' );

	if ( 'status' === $toggle ) {
		xdebug_status();

		return;
	}

	$map = [
		'host' => 'XDH',
		'key'  => 'XDK',
		'port' => 'XDP',
	];
	if ( array_key_exists( $toggle, $map ) ) {
		$var = $args( 'value' );
		echo colorize( "Setting <light_cyan>{$map[$toggle]}={$var}</light_cyan>" ) . PHP_EOL . PHP_EOL;
		write_env_file( $run_settings_file, [ $map[ $toggle ] => $var ] );
		echo PHP_EOL . PHP_EOL . colorize( "Tear down the stack with <light_cyan>down</light_cyan> and restar it to apply the new settings!\n" );

		return;
	}

	$value = 'on' === $toggle ? 1 : 0;
	echo 'XDebug status: ' . ( $value ? light_cyan( 'on' ) : magenta( 'off' ) );

	if ( $value === (int) getenv( 'XDE' ) ) {
		return;
	}

	write_env_file( $run_settings_file, [ 'XDE' => $value ], true );

	echo "\n\n";

	$restart_services = ask(
		'Would you like to restart the WordPress (NOT the database) and Codeception services now?',
		'yes'
	);
	if ( $restart_services ) {
		restart_php_services();
	} else {
		echo colorize(
			"\n\nTear down the stack with <light_cyan>down</light_cyan> and restar it to apply the new settings!\n"
		);
	}
}

/**
 * Updates the stack images by pulling the latest version of each.
 */
function update_stack_images() {
	echo "Updating the stack images...\n\n";
	tric_realtime()( [ 'pull', '--include-deps' ] );
	echo light_cyan( "\n\nStack images updated.\n" );
}

/**
 * Run a command using the `npm` service.
 *
 * If `common` is available in the target and the command dos not fail, then the user will be prompted to run the same
 * command on `common`.
 *
 * @param array<string> $command The `npm` command to run, e.g. `['install','--save-dev']` in array format.
 */
function tric_run_npm_command( array $command ) {
	$using = tric_target();
	echo light_cyan( "Using {$using}\n" );

	setup_id();
	$status = tric_realtime()( array_merge( [ 'run', '--rm', 'npm' ], $command ) );

	if ( 0 !== $status ) {
		// If the composer command failed there's no point in trying the same on `common`
		return;
	}

	if ( ! file_exists( tric_plugins_dir( "{$using}/common" ) ) ) {
		return;
	}

	if ( ask( "\nWould you like to run that npm command against common?", 'yes' ) ) {
		tric_switch_target( "{$using}/common" );

		echo light_cyan( "Temporarily using " . tric_target() . "\n" );

		tric_realtime()( array_merge( [ 'run', '--rm', 'npm' ], $command ) );

		Tribe\Test\tric_switch_target( $using );

		echo light_cyan( "Using " . tric_target() . " once again\n" );
	}
}

/**
 * Run a command using the `composer` service.
 *
 * If `common` is available in the target and the command dos not fail, then the user will be prompted to run the same
 * command on `common`.
 *
 * @param array<string> $command The `composer` command to run, e.g. `['install','--no-dev']` in array format.
 */
function tric_run_composer_command( array $command ) {
	$using = tric_target();
	echo light_cyan( "Using {$using}\n" );

	setup_id();
	$status = tric_realtime()( array_merge( [ 'run', '--rm', 'composer' ], $command ) );

	if ( 0 !== $status ) {
		// If the composer command failed there's no point in trying the same on `common`
		return;
	}

	if ( ! file_exists( tric_plugins_dir( "{$using}/common" ) ) ) {
		return;
	}

	if ( ask( "\nWould you like to run that composer command against common?", 'yes' ) ) {
		tric_switch_target( "{$using}/common" );

		echo light_cyan( "Temporarily using " . tric_target() . "\n" );

		tric_realtime()( array_merge( [ 'run', '--rm', 'composer' ], $command ) );

		tric_switch_target( $using );

		echo light_cyan( "Using " . tric_target() . " once again\n" );
	}
}
