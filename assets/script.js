jQuery(document).ready(function($) {
    let migrationInterval = null;
    
    // Initialize button states on page load
    var isActive = $("#stop-migration-btn").length > 0;
    var isPaused = $("#start-migration-btn").text().indexOf("Resume") !== -1;
    
    if (isActive) {
        // If migration is active, show stop button
        $("#start-migration-btn").hide();
        $("#stop-migration-btn").show();
    } else if (isPaused) {
        // If paused, show resume button
        $("#start-migration-btn").show().text("Resume Migration");
        $("#stop-migration-btn").hide();
    }
    
    // Settings Form
    $("#wix-migration-settings-form").on("submit", function(e) {
        e.preventDefault();
        
        const importCategories = $("#import_categories").is(":checked") ? "1" : "0";
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_save_settings",
            nonce: wixWooMigration.nonce,
            import_categories: importCategories
        }, function(response) {
            if (response.success) {
                showMessage("settings-message", "success", response.data.message);
            } else {
                showMessage("settings-message", "error", response.data.message);
            }
        });
    });
    
    // CSV Upload
    $("#wix-csv-upload-form").on("submit", function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        const fileInput = $("#wix-csv-file")[0];
        
        if (!fileInput.files.length) {
            showMessage("upload-message", "error", "Please select a file");
            return;
        }
        
        showMessage("upload-message", "info", "Uploading and parsing CSV file...");
        
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
                console.log("Upload response:", response);
                if (response.success) {
                    showMessage("upload-message", "success", response.data.message);
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showMessage("upload-message", "error", response.data.message || "Upload failed");
                }
            },
            error: function(xhr, status, error) {
                console.error("Upload error:", xhr, status, error);
                showMessage("upload-message", "error", "Upload failed: " + error);
            }
        });
    });
    
    // Start/Resume Migration
    $("#start-migration-btn").on("click", function() {
        $(this).prop("disabled", true).text("Starting...");
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_start_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                startMigrationProcess();
            } else {
                $("#start-migration-btn").prop("disabled", false).text("Start Migration");
                showMessage("migration-message", "error", response.data.message);
            }
        });
    });
    
    // Stop Migration
    $("#stop-migration-btn").on("click", function() {
        $(this).prop("disabled", true).text("Pausing...");
        
        $.post(wixWooMigration.ajax_url, {
            action: "wix_woo_stop_migration",
            nonce: wixWooMigration.nonce
        }, function(response) {
            if (response.success) {
                stopMigrationProcess();
                showMessage("migration-message", "info", response.data.message);
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                $("#stop-migration-btn").prop("disabled", false).text("Pause Migration");
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
        $("#stop-migration-btn").show().prop("disabled", false);
        $("#reset-migration-btn").show();
        
        migrationInterval = setInterval(function() {
            processBatch();
        }, 2000); // Increased interval to reduce load
    }
    
    function stopMigrationProcess() {
        if (migrationInterval) {
            clearInterval(migrationInterval);
            migrationInterval = null;
        }
        
        $("#stop-migration-btn").hide();
        $("#start-migration-btn").show().prop("disabled", false).text("Resume Migration");
        $("#reset-migration-btn").show();
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
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            } else {
                stopMigrationProcess();
                showMessage("migration-message", "error", response.data.message);
            }
        }).fail(function(xhr, status, error) {
            console.error("Batch processing error:", xhr, status, error);
            stopMigrationProcess();
            showMessage("migration-message", "error", "Batch processing failed: " + error);
        });
    }
    
    function updateProgress(data) {
        $("#processed-count").text(data.processed);
        $("#failed-count").text(data.failed);
        $("#skipped-count").text(data.skipped);
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
        
        if (type !== "info") {
            setTimeout(function() {
                $msg.fadeOut();
            }, 5000);
        }
    }
});