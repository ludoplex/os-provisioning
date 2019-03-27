#!/bin/bash
dir='/var/www/nmsprime'

handle_module() {
	if [[ "$1" == 'base' ]]
	then
		cfg="$dir/Install/config.cfg"
	else
		cfg="$(dirname $1)/Install/config.cfg"
	fi

	if [[ -f "$cfg" ]]; then
		while read -r line; do
			files+=($(echo "$line" | cut -d'=' -f2 | xargs))
		done < <(awk '/\[files\]/{flag=1;next}/\[/{flag=0}flag' "$cfg" | grep '=')

		for file in $(grep '^configfiles' "$cfg" | cut -d'=' -f2 | grep -o '"[^"]\+"' | tr -d '"'); do
			files+=("$(dirname $1)/$file")
		done
	fi
}

display_help() {
	echo "Usage: $0 -p mysql_root_password [> output-file.tar.gz]" >&2
	exit 1
}

while getopts ":p:h" opt; do
	case $opt in
		h)
			display_help
			exit 0
			;;
		p)
			pw="$OPTARG"
			;;
		\?)
			echo "Invalid option: -$OPTARG" >&2
			display_help
			exit 1
			;;
	esac
done

if [[ -z "$pw" ]]; then
	display_help
	exit 1
fi

excludes=(
	"$dir/storage/app/tmp"
	"$dir/storage/framework"
)

folders=(
	"$dir/storage"
	'/etc/firewalld'
	'/tftpboot'
	'/var/lib/cacti/rra'
	'/var/lib/dhcpd'
	'/var/named/dynamic'
)

files=()
rpm_files=$(rpm -qa 'nmsprime*' -c)
if [[ -n "$rpm_files" ]]
then
	# rpm
	readarray -t files <<< "$rpm_files"
else
	# git
	handle_module base
	for module in "$dir"/modules/*/module.json; do
		handle_module "$module"
	done
fi

tar --exclude-from <(IFS=$'\n'; echo "${excludes[*]}") --transform="s|dev/stdin|databases.sql|;s|^|$(date +%Y%m%dT%H%M%S)/|" --hard-dereference -czh /dev/stdin "${folders[@]}" "${files[@]}" <<< "$(mysqldump -u root --password="$pw" --databases cacti director icinga2 icingaweb2 nmsprime nmsprime_ccc)"
