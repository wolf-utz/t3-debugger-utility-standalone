#!/usr/bin/env bash

#
# Based on the TYPO3 core test runner.
#

# Function to write a .env file in Build/testing-docker/local
# This is read by docker-compose and vars defined here are
# used in Build/testing-docker/local/docker-compose.yml
setUpDockerComposeDotEnv() {
    # Delete possibly existing local .env file if exists
    [ -e .env ] && rm .env
    echo "ROOT_DIR"=${ROOT_DIR} >> .env
    echo "EXTRA_TEST_OPTIONS=${EXTRA_TEST_OPTIONS}" >> .env
    echo "PHP_VERSION=${PHP_VERSION}" >> .env
}

# Test if docker-compose exists, else exit out with error
if ! type "docker-compose" > /dev/null; then
  echo "This script relies on docker and docker-compose. Please install" >&2
  exit 1
fi

# Load help text into $HELP
read -r -d '' HELP <<EOF
Test runner.

Usage: $0 [options] [file]

No arguments: Run all acceptance tests (not .side files)

Options:
    -s <...>
        Specifies which test suite to run
            - build: Test if the project can be build with errors.
            - unit: Runs the unit tests
            - quality: Runs the code quality tests.

    -e "<test options>"
        Only with -s acceptance
        Additional options to send to codeception tests.

    -p <5.6|7.0|7.1|7.2|7.3>
        Specifies the PHP version to be used

    -h
        Show this help.

Examples:
    build/scripts/console.sh -s quality -p 5.6
    build/scripts/console.sh -s unit -p 7.0 -e "--verbose"
EOF

# Gets the realpath.
realpath() {
    if ! pushd $1 &> /dev/null; then
        pushd ${1##*/} &> /dev/null
        echo $( pwd -P )/${1%/*}
    else
        pwd -P
    fi
    popd > /dev/null
}

# Go to the directory this script is located, so everything else is relative
# to this dir, no matter from where this script is called.
THIS_SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null && pwd )"
cd "$THIS_SCRIPT_DIR" || exit 1
# Go to directory that contains the local docker-compose.yml file
cd ../testing-docker || exit 1

# Option defaults
ROOT_DIR=$(realpath $PWD"/../..")
OPTION="unit"
PHP_VERSION="7.1"
EXTRA_TEST_OPTIONS=""

# Option parsing
# Reset in case getopts has been used previously in the shell
OPTIND=1
# Array for invalid options
INVALID_OPTIONS=();
# Simple option parsing based on getopts (! not getopt)
while getopts ":s:d:p:e:xy:huv" OPT; do
    case ${OPT} in
        s)
            OPTION=${OPTARG}
            ;;
        e)
            EXTRA_TEST_OPTIONS=${OPTARG}
            ;;
        p)
            PHP_VERSION=${OPTARG}
            ;;
        h)
            echo "${HELP}"
            exit 0
            ;;
        \?)
            INVALID_OPTIONS+=(${OPTARG})
            ;;
        :)
            INVALID_OPTIONS+=(${OPTARG})
            ;;
    esac
done

# Suite execution
case ${OPTION} in
    build)
        setUpDockerComposeDotEnv
        docker-compose run build
        SUITE_EXIT_CODE=$?
        docker-compose down
        ;;
    unit)
        setUpDockerComposeDotEnv
        docker-compose run unit
        SUITE_EXIT_CODE=$?
        docker-compose down
        ;;
    quality)
        setUpDockerComposeDotEnv
        docker-compose run quality
        SUITE_EXIT_CODE=$?
        docker-compose down
        ;;
    *)
        echo "Invalid -s option argument ${OPTARG}" >&2
        echo >&2
        echo "${HELP}" >&2
        exit 1
esac

exit $SUITE_EXIT_CODE