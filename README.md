# HM GitHub Stats

## Configuration
You need to be user with ID 1 to configure the plugin.

Create a personal token on your Github profile. Go here: [https://github.com/settings/applications](https://github.com/settings/applications) and click Generate new token. Have `repo`, `public_repo` and `read:org` ticked, the rest are not necessary.

Navigate to Users -> Your profile, and scroll down towards the bottom. Copy and paste the personal token into the field. After saving, you should see all the organisations your Github user belongs to. Select the ones you will potentially want to gather data from, and click save.

WordPress will go and fetch all the usage statistics. This might take a while, and you might have partial data from some of the repositories before all of them arrive. It should be a 5-10 minute process depending on your server's connection speed, Github's availability, the number of organisations and the number of repositories in those organisations.

If you wish to reset all the settings, tick Purge Settings, and update.

## Usage
Go to Appearance -> Widgets. There you will find one that's called Github Statistics. Drag it where you want it to appear, give it a name, optionally give it an absolute link (http:// or https:// in front), and save. If all data gathering is finished by this point, you should see the data in the sidebar.

## Contribution guidelines ##

See https://github.com/humanmade/hm-github-stats/blob/master/CONTRIBUTING.md