#!/bin/bash
# Azure App Service deployment script
# This runs after git push to Azure

# Navigate to app root
cd /home/site/wwwroot

# Run the main startup script
if [ -f "startup.sh" ]; then
    chmod +x startup.sh
    ./startup.sh
fi
