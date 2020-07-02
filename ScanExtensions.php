<?php

global $wgAutoloadClasses;
$wgAutoloadClasses = $wgAutoloadClasses ?? [];
$IP = getenv( 'MW_INSTALL_PATH' );

if ( !$IP ) {
	die( "Please set the MW_INSTALL_PATH environment variable\n" );
}

if ( !is_file( "$IP/thumb.php" ) ) {
	die( "MediaWiki not found in $IP\n" );
}

require_once $IP . '/includes/AutoLoader.php';
require_once $IP . '/includes/utils/AutoloadGenerator.php';
require $IP . '/vendor/autoload.php';
require_once $IP . '/tests/common/TestsAutoLoader.php';

use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\Node;


class ScratchExtensions {

	// TODO: give it cli argument for one class to go thru

	// Will process MW Extensions that are in the shared namespace as core files
	// and spit out whether or not they are stable-y overriding functions
	public function run( array $patchFiles ) {
		foreach ( $patchFiles as $file ) {
			$matches = $this->processFile($file);
		}
	}

	private function getExtensionInfo( $res, $unstableMethods ) {
		$baseUrl = 'https://gerrit.wikimedia.org/g/mediawiki/extensions/';
			foreach ( $res as $extRepo => $ext ) {
				echo "Checking $extRepo \n";
				foreach ( $ext['Matches'] as $match ) {
					// this will only match MW owned extensions
					preg_match( '/Extension:(\w+)/', $extRepo, $matches );
					if ($matches) {
							$extName = $matches[1];
							$url = $baseUrl . $extName . '/+/' . $ext['Revision'] . '/' . $match['Filename'] . '?format=TEXT';
							$base64File = file_get_contents($url);
							$decoded = base64_decode($base64File);
						try {
							$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
							$stmts = $parser->parse($decoded);
							$nodeFinder = new NodeFinder();
							// TODO account for use stmts and namespace declarations to SKIP non-mw extended classes
							// TODO in MW namespace
							$methods = $nodeFinder->findInstanceOf($stmts, Node\Stmt\ClassMethod::class);
							foreach ($methods as $method) {
								if ( in_array($method->name->toString(), $unstableMethods)) {
									echo "$extName overrides unstable method $method->name in file" . $match['Filename'] . "\n" ;
								}
							}
						} catch (Error $error) {
							echo "Parse error: {$error->getMessage()}\n";
							return;
						}
					}
				}
			}
	}

	private function getExtensionUrl() {
//		$submodules = array_chunk(
//			explode("\n", file_get_contents('https://raw.githubusercontent.com/MWStake/nonwmf-extensions/master/.gitmodules')), 3
//		);
//		foreach ( $submodules as $module ) {
//			var_dump($module);
//			$name = $module[0];
//			$url = $module[2];
//			if (substr($url, -4) == '.git') {
//				var_dump('GIT!!' . ' $url');
//			}
//			if (strpos($url, 'github.com') !== false) {
//				str_replace( 'git@github.com', 'https://github.com/', $url);
//			} elseif (strpos($url, 'https://bitbucket.org/') !== false) {
//
//			} elseif (strpos($url, 'https://gitlab.com/') !== false) {
//
//			}
//
//		}
//		url = config[section]['url']
//        if url.endswith('.git'):
//	        url = url[:-4]
//        if 'github.com' in url:
//            name = url.replace('git@github.com:', '').replace('https://github.com/', '')
//            repos.append((name, gh_repo(name)))
//        elif 'bitbucket.org' in url:
//            name = url.replace('https://bitbucket.org/', '')
//            repos.append((name, bitbucket_repo(name)))
//        elif 'gitlab.com' in url:
//            name = url.replace('https://gitlab.com/', '')
//            repos.append((name, gitlab_repo(name)))
//        elif 'phabricator.nichework.com' in url:
//            # FIXME: implement
//            continue
//        else:
//	        raise RuntimeError(f'Unsure how to handle URL: {url}')
	}


	// Step 1: For a given file, get all unstable methods.
	// Step 2: Find all places in extensions that extend the file
	// Step 3: In the extension file, find where method is overridden

	/**
	 * @param $file
	 * @param $matches
	 * @param array $q
	 * @param string $url
	 * @return mixed
	 * @throws ReflectionException
	 */
	private function processFile($file) {
		global $IP;
		$file = "$IP/$file";
		
		echo "PROCESSING $file\n";
		if ( !is_file($file) ) {
			echo $file . " not found\n";
			return;
		}
		$classNameInfo = $this->getClassName( $file );
		if ( !$classNameInfo ) {
			echo("$file  is not a class\n");
			return;
		}
		$unstableMethods= $this->getUnstableMethods( $classNameInfo['namespace'] . '\\' . $classNameInfo['class'] );
		$extensionUsages = $this->queryCodeSearch( $classNameInfo['class'] );
		if ( !$extensionUsages ) {
			echo "no matches\n";
			return;
		}
		$this->getExtensionInfo($extensionUsages, $unstableMethods);
	}

	/**
	 * @param $class
	 * @return mixed|null
	 */
	private function queryCodeSearch( $class ) {
		$url = 'https://codesearch.wmflabs.org/extensions/api/v1/search?';
		$q = [
			'stats' => 'fosho',
			'repos' => '*',
			'rng'=> ':50',
			'q' => null,
			'i' => 'nope'
		];

		$q['q'] = "extends $class\\b";
		$query = http_build_query($q);

		$httpResponse = file_get_contents($url . $query);
		$jsonResults = json_decode($httpResponse, true); // json response of stuff extending that class
		if ($jsonResults['Results']) {
			return $jsonResults['Results'];
		}
	}

	private function isMagicMethod( $method ) {
		return preg_match( '/^__\w+/', $method );
	}
	/**
	 * @param $file
	 * @param array $q
	 * @param string $url
	 * @param $matches
	 * @return array
	 * @throws ReflectionException
	 */
	private function getUnstableMethods( $class ): array {
		$classMethods = get_class_methods( $class );
		if (!$classMethods) {
			echo "No methods for $class\n";
		}
		$unstableMethods = [];
		foreach ($classMethods as $method) {
			$refClass = new ReflectionClass($class);
			$refMethod = $refClass->getMethod($method);
			if ( $refMethod->isPrivate()
				|| $refMethod->isAbstract()
				|| $refMethod->isConstructor()
				|| $this->isMagicMethod( $refMethod->name ) ) {
				continue;
			}
			$comment = $refMethod->getDocComment();
			if ( !preg_match('/@stable for overriding/', $comment ) ) { // function is not stable
				$unstableMethods[] = $method;
			}
		}
		return $unstableMethods;
	}

	private function getClassName($file) {
		$classContents = file_get_contents($file);
		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
		$stmts = $parser->parse($classContents);
		$nodeFinder = new NodeFinder();
		$class = $nodeFinder->findInstanceOf($stmts, Node\Stmt\Class_::class);
		if ( !$class ) {
			return null;
		}
		$classInfo = [
			'class' => '',
			'namespace' => ''
		];
		$classInfo['class'] = $class[0]->name->toString();
		$ns = $nodeFinder->findInstanceOf($stmts, Node\Stmt\Namespace_::class);
		if ( $ns ) {
			$classInfo['namespace'] = $ns[0]->name->toString();
		}
		return $classInfo;
	}
}

$thing = new ScratchExtensions();
$files = $argv;
array_shift( $files );

if ( !$files ) {
	die( "Please specify files on the command line, relative to the MediaWiki installation directory.\n" );
}

$thing->run( $files );
