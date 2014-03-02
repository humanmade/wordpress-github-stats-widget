# HM GitHub Stats

## Configuration
You need to be user with ID 1 to configure the plugin.

Create an application on your Github profile. Go here: [https://github.com/settings/applications](https://github.com/settings/applications) and click Register New Application.

Navigate to Users -> Your profile, and scroll down towards the bottom. Copy and paste the client ID and client secret from your Github application, and click update. A link will appear. Click on the link, which will take you to Github's OAuth prompt, and authorize access. The plugin only reads data from Github, it does not write anything to it.

You may have to wait and refresh until WordPress fetches all the organisations you belong to. Tick the boxes you want to count, and click Update Profile again. This will start data gathering. Depending on the number of repositories you have across the organisations, this might take a while.

If you wish to reset all the settings, tick Purge Settings, and update.

## Usage
Go to Appearance -> Widgets. There you will find one that's called Github Statistics. Drag it where you want it to appear, give it a name, optionally give it an absolute link (http:// or https:// in front), and save. If all data gathering is finished by this point, you should see the data in the sidebar.

## Contribution guidelines ##

See https://github.com/humanmade/hm-github-stats/blob/master/CONTRIBUTING.md