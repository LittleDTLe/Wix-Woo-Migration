jQuery(document).ready(function($) {
        let migrationInterval = null;
        
        // CSV Upload
        $("#wix-csv-upload-form").on("submit", function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            const fileInput = $("#wix-csv-file")[0];
            
            if (!fileInput.files.length) {
                showMessage("upload-message", "error", "Please select a file");
                return;
            }
            
            formData.append("wix_csv_file", fileInput.files[0]);
            formData.append("action", "wix_woo_upload_csv");
            formData.append("nonce", wixWooMigration.nonce);
            
            $.ajax({
                url: wixWooMigration.ajax_url,
                type: "POST",
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showMessage("upload-message", "success", response.data.message);
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showMessage("upload-message", "error", response.data.message);
                    }
                },
                error: function() {
                    showMessage("upload-message", "error", "Upload failed");
                }
            });
        });
        
        // Start/Resume Migration
        $("#start-migration-btn").on("click", function() {
            $.post(wixWooMigration.ajax_url, {
                action: "wix_woo_start_migration",
                nonce: wixWooMigration.nonce
            }, function(response) {
                if (response.success) {
                    startMigrationProcess();
                } else {
                    showMessage("migration-message", "error", response.data.message);
                }
            });
        });
        
        // Stop Migration
        $("#stop-migration-btn").on("click", function() {
            $.post(wixWooMigration.ajax_url, {
                action: "wix_woo_stop_migration",
                nonce: wixWooMigration.nonce
            }, function(response) {
                if (response.success) {
                    stopMigrationProcess();
                    showMessage("migration-message", "info", response.data.message);
                }
            });
        });
        
        // Reset Migration
        $("#reset-migration-btn").on("click", function() {
            if (!confirm("Are you sure you want to reset the migration? This will delete all progress.")) {
                return;
            }
            
            $.post(wixWooMigration.ajax_url, {
                action: "wix_woo_reset_migration",
                nonce: wixWooMigration.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        });
        
        function startMigrationProcess() {
            $("#start-migration-btn").hide();
            $("#stop-migration-btn").show();
            
            migrationInterval = setInterval(function() {
                processBatch();
            }, 1000);
        }
        
        function stopMigrationProcess() {
            if (migrationInterval) {
                clearInterval(migrationInterval);
                migrationInterval = null;
            }
            
            $("#stop-migration-btn").hide();
            $("#start-migration-btn").show().text("Resume Migration");
        }
        
        function processBatch() {
            $.post(wixWooMigration.ajax_url, {
                action: "wix_woo_process_batch",
                nonce: wixWooMigration.nonce
            }, function(response) {
                if (response.success) {
                    updateProgress(response.data);
                    
                    if (response.data.is_complete) {
                        stopMigrationProcess();
                        showMessage("migration-message", "success", "Migration completed successfully!");
                        $("#start-migration-btn").hide();
                        $("#stop-migration-btn").hide();
                    }
                } else {
                    stopMigrationProcess();
                    showMessage("migration-message", "error", response.data.message);
                }
            });
        }
        
        function updateProgress(data) {
            $("#processed-count").text(data.processed);
            $("#failed-count").text(data.failed);
            $("#images-count").text(data.images_imported);
            $("#progress-fill").css("width", data.percentage + "%");
            $("#progress-text").text(data.percentage + "%");
        }
        
        function showMessage(elementId, type, message) {
            const $msg = $("#" + elementId);
            $msg.removeClass("notice-success notice-error notice-info")
                .addClass("notice-" + type)
                .html("<p>" + message + "</p>")
                .show();
            
            setTimeout(function() {
                $msg.fadeOut();
            }, 5000);
        }
});