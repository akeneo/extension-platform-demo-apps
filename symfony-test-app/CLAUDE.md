# Keep this app in sync with extension-platform-demo-apps

This app exists in two copies, and every change must land in both:

- this standalone repo (`~/Projects/extension-platform-apps/extension-platform-symfony-test-app`), which is pushed to Upsun to deploy the app;
- the `symfony-test-app/` folder of the GitHub repo akeneo/extension-platform-demo-apps (local clone: `~/Projects/extension-platform-apps/extension-platform-demo-apps`), which is the reference copy.

So after each commit here, replay it in the demo-apps repo and push that repo to GitHub:

    git format-patch -1 HEAD -o /tmp/sync                           # here
    git am --directory=symfony-test-app /tmp/sync/*.patch           # in extension-platform-demo-apps
