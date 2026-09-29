# Host Inventory for Zabbix

A Zabbix 7.0 frontend module: **Inventory → Host Inventory** lists every Linux server, Windows
server and website Zabbix monitors — who it belongs to, where it is reached from, and what it has.
OS, hostname, architecture, CPU, memory, disks and services come live from the host's items; the
business fields (client, branch, type, access …) are defined by an admin and kept on each host as a
tag, `inv:<field>`.

- Tabs by kind and a **Needs attention** tab (a required field empty, a value outside its list, not
  reporting for a day, OS past end of support)
- Search and filters: any field, host group, OS
- A host's details: every measured value with the item it comes from, its disks and services, and
  its Zabbix host inventory
- Edit one host, many at once, or by CSV (previewed); export CSV or Excel
- **Add website**: a web check every minute, a problem when it fails, the certificate checked
- **Fill it from the items**: turn Zabbix's own host inventory on, so items fill it by themselves
- Read-only for Zabbix Users; every change backed up first

Needs Zabbix 7.0 and PHP 8. No build step, no database of its own.

## Install

### Zabbix frontend from packages

```bash
git clone https://github.com/karthick-dkk/zabbix-host-inventory
cd zabbix-host-inventory
ZABBIX_URL=https://zabbix.example.com ZABBIX_TOKEN=<super admin API token> sudo ./install.sh
sudo systemctl reload php-fpm        # or php8.x-fpm
```

`install.sh` copies the module into `/usr/share/zabbix/modules/host_inventory` (the copy there
first goes to `/var/backups/zabbix-host-inventory/<date>/`), makes the data folder
`/var/lib/zabbix-host-inventory` owned by the user PHP runs as, and registers and enables the
module. Without `ZABBIX_URL` and a token (or `ZABBIX_USER` + `ZABBIX_PASSWORD`), enable it in
**Administration → General → Modules → Scan directory**. `./install.sh --help` lists the options;
`--dry-run` shows what it would do. Run it again to update.

### Zabbix in Docker (zabbix-web-nginx-*)

Mount the module and give PHP a writable data folder:

```yaml
services:
  web:
    environment:
      HOST_INVENTORY_DATA_DIR: /var/lib/zabbix/host-inventory
    volumes:
      - ./zabbix-host-inventory:/usr/share/zabbix/modules/host_inventory:ro
      - zabbix-web-data:/var/lib/zabbix      # empty and owned by zabbix in the image
volumes:
  zabbix-web-data:
```

Then enable it (Administration → General → Modules → Scan directory), or from the clone:
`ZABBIX_URL=… ZABBIX_TOKEN=… ./install.sh --modules-dir .. --no-data-dir` (it sees the module is
already in place and only enables it). To update: `git pull`; the mount follows.

### The data folder

It holds the field list, settings (extra end-of-support rows, the certificate agent), backups of
changed values and a pending CSV import. Values themselves are host tags in Zabbix. Without a
writable data folder the page shows a red banner and refuses changes (no backup, no change).

## What is listed

A host is listed when it is linked to a Linux or Windows agent template (any template whose name
contains "Linux by Zabbix agent" or "Windows by Zabbix agent", the SISA ones included), or when it
has a web check. Nothing is created or deleted to list a host. Each person sees the hosts Zabbix
lets them see.

## Measured: read from Zabbix, never typed

**Server name** is the host's visible name (`{HOST.NAME}`), static. **IP address** is `{HOST.IP}`:
the main interface's IP, even when Zabbix connects by DNS; with no IP there, the technical name
(`{HOST.HOST}`) when that is an IP address — many hosts are named by their IP — else the
interface's DNS name. The technical name is shown under the server name only when it differs from
both. Everything below comes from the host's items, live.

| Column | From |
|---|---|
| Hostname | `system.hostname` (the name the OS reports) |
| OS | `system.sw.os[name]` when the host has it; otherwise `system.sw.os`. On Linux that item is usually the kernel string (`Linux version 6.8.0-139-generic …`), so the distribution is taken from the kernel only when it says so reliably (`el7`, `el9_4` → RHEL-family; `amzn2`; `-Ubuntu` → Ubuntu without a version). Otherwise **not reported**, with the item that would fix it. |
| Kernel / build | `system.uname` (Linux kernel release; Windows build number) |
| Architecture | `system.sw.arch` |
| CPU, Memory | `system.cpu.num`, `vm.memory.size[total]` |
| Up for, agent (details) | `system.uptime`, `agent.version` |
| Disks | every file system from `vfs.fs.dependent.size[…]` / `vfs.fs.size[…]`, `/` or `C:` first; container bind files and runtime mounts (`/etc/…`, `/proc`, `/run`, `/snap` …) left out |
| Services | Windows services (`service.info[…,state]`), systemd units ("Systemd by Zabbix agent 2"), the security agents the SISA template watches (`<name>.agent.status`: 1 running, 0 down; not listed when `<name>.agent.enabled` says not installed, or the item is unsupported), and `proc.num[<name>]` checks |
| Website | the web check's response code and time, its error, and the certificate expiry (`cert.not_after`) |
| Monitored by | the host's proxy or proxy group, or the Zabbix server |
| Last seen | the newest value from the host; **not reporting** after a day, or never |


**An agent in a container** (e.g. `zabbix/zabbix-agent2`) sees only its own file systems — a few files Docker
bind-mounts — so no disks. Mount the machine's root into it read-only, `-v /:/hostfs:ro,rslave`:
disk discovery then finds `/hostfs`, `/hostfs/boot` …, and Host Inventory shows them as `/`, `/boot`
(the prefixes `/hostfs`, `/rootfs` and `/host` are taken off).

Unknown stays unknown: an item Zabbix does not have is shown as such, never as 0.

## Zabbix's own host inventory, filled from the items

Items can fill Zabbix's host inventory by themselves ("Populates host inventory field"). The SISA
Linux template links `system.hostname` → Alias, `system.sw.os` → OS, `system.sw.arch` → HW
architecture, `system.cpu.num` → Chassis, `vm.memory.size[total]` → Model; the stock Linux and
Windows templates link `system.hostname` → Name and `system.sw.os` → OS. Zabbix does this only for a
host whose inventory mode is **Automatic**. Each host's details show its mode and what the items
have written; when listed hosts keep no inventory (mode Disabled), a note above the table offers
**Fill it from the items**, which sets them to Automatic (Admins; hosts in Manual mode, whose
fields may have been typed in Zabbix, are left alone). A field fills when its item next stores a
value: items that discard unchanged values (the SISA ones keep a 12 h or 1 day heartbeat) fill it
within that time.

## Described: fields an admin manages

The typed fields are data. **Fields** (Super admins) adds, renames, reorders, hides and removes them;
a field has a type (text, choice, date, number, or client — the Cluster Management list), and
can be required, shown in the list, and offered as a filter. The defaults: Client, Branch, Type
(CI, DI, On-Prem — the list Cluster Management uses too), Access from, Owner team, Environment.

Each host's value is a Zabbix host tag `inv:<key>` (`inv:branch=Chennai`), so Problems, maps and
dashboards can filter by it. When ElasticVue Pro's Cluster Management runs on the same Zabbix, the Client list comes from
it, and its servers show their client and type (`evp-client`, the client's type) until a value is
typed. Otherwise clients are typed freely.

Removing a field keeps its values on the hosts unless "also remove the tag from every host" is
ticked. Renaming changes the name only; the key stays.

## Changing values

Admins and Super admins, on hosts Zabbix lets them write:

- **A host's details**: click its row; the form holds every field.
- **Many hosts**: tick them, pick a field and a value, **Apply**.
- **Import CSV**: the file **Export CSV** writes, edited — a Host column (the technical name, the
  server name or the IP address) and a column per field (by name). An empty cell leaves a value as it is; `-` clears it. A preview lists every change and
  every refused line before anything is saved.

Every change takes a backup of the `inv:` tags it touches first (`inventory-backups/` in the data
folder, newest ten kept). **Export Excel** writes the rows shown, measured columns as numbers.

## Needs attention

A host with a required field empty, a value outside its field's choices, no data for a day, or an
OS past its end of support. End-of-support dates are built in for the common Linux and Windows
versions; admins add their own rows on the Fields page ("OS name contains", date). A row matches
when the OS name contains its text with no digit straight after it.

## Websites

**Add website** (Admins): an address, a name, the fields, and where it is checked from (the Zabbix
server, a proxy, a proxy group). It makes a host in the group **Websites** (and the client's host
group, when there is one) with a web check of the address every minute and a HIGH problem when it
fails, tagged `managed-by: elasticvue-inventory`. For an https address the certificate is checked
too when a Super admin has set, on the Fields page, an agent 2 to do it: the host gets an agent
interface on that agent and the template **Website certificate by Zabbix agent 2**.

## Permissions

| Who | Can |
|---|---|
| Zabbix User | see the hosts Zabbix lets them see; export |
| Admin | also change values, import, add websites, turn Zabbix inventory on — on hosts they may write |
| Super admin | also manage the fields and settings |

## Development

```bash
npm install                    # jsdom, for the page tests
php tests/spec.test.php        # fields, measurements, support dates, attention, CSV
node --test tests/*.test.mjs   # the page pressed in jsdom; Zabbix constants; manifest
```

`HINV_DATA=<file.json> node --test tests/page.test.mjs` also renders data copied from a live
page (the JSON passed to `HostInventory.init`).

## License

Apache License 2.0 — see LICENSE.
