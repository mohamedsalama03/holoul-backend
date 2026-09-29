<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

enum Permission: string
{
    case ReadOwnIdentity = 'identity.self.read';
    case UpdateOwnIdentity = 'identity.self.update';
    case ReadOwnCustomer = 'customers.self.read';
    case UpdateOwnCustomer = 'customers.self.update';
    case ReadCustomerDirectory = 'customers.directory.read';
    case ReadStaff = 'identity.staff.read';
    case ManageStaff = 'identity.staff.manage';
    case ManageSecurity = 'identity.security.manage';
    case ManageTaxonomy = 'taxonomy.manage';
    case ReadIntake = 'intake.read';
    case ReadAllIntake = 'intake.read_all';
    case AssignIntake = 'intake.assign';
    case ReviewIntake = 'intake.review';
    case RequestIntakeInformation = 'intake.information';
    case StartIntakeDiscovery = 'intake.discovery';
    case RejectIntake = 'intake.reject';
    case ReadDocuments = 'documents.read';
    case DownloadDocuments = 'documents.download';
    case ReadDiscovery = 'discovery.read';
    case ManageDiscovery = 'discovery.manage';
    case CompleteDiscovery = 'discovery.complete';
    case ReadProposals = 'proposals.read';
    case CreateProposal = 'proposals.create';
    case EditProposal = 'proposals.edit';
    case ApproveProposal = 'proposals.approve';
    case IssueProposal = 'proposals.issue';
    case WithdrawProposal = 'proposals.withdraw';
    case ReadOwnProposals = 'proposals.self.read';
    case AcceptOwnProposal = 'proposals.self.accept';
    case DeclineOwnProposal = 'proposals.self.decline';
    case ReadProjects = 'projects.read';
    case ReadAllProjects = 'projects.read_all';
    case ConvertProject = 'projects.convert';
    case ManageProject = 'projects.manage';
    case TransitionProject = 'projects.transition';
    case ManageProjectTeam = 'projects.team.manage';
    case ManageMilestones = 'projects.milestones.manage';
    case PublishProjectUpdates = 'projects.updates.publish';
    case ReadProjectDocuments = 'projects.documents.read';
    case UploadProjectDocuments = 'projects.documents.upload';
    case ReadOwnProjects = 'projects.self.read';
    case ConfirmOwnProject = 'projects.self.confirm';
    case ReadOwnProjectDocuments = 'projects.self.documents.read';
    case UseOwnAI = 'ai.self.use';
    case ApplyOwnAI = 'ai.self.apply';
    case UseStaffAI = 'ai.use';
    case ApplyStaffAI = 'ai.apply';
    case ReadOwnNotifications = 'notifications.self.read';
    case ManageOwnNotifications = 'notifications.self.manage';
    case ReadNotificationDelivery = 'notifications.delivery.read';
    case ReplayNotificationDelivery = 'notifications.delivery.replay';
    case ReadReporting = 'reporting.read';
    case InvestigateAudit = 'audit.investigate';
    case ReadPortfolio = 'portfolio.read';
    case ManagePortfolio = 'portfolio.manage';
    case PublishPortfolio = 'portfolio.publish';
    case ReadContact = 'contact.read';
    case ManageContact = 'contact.manage';
    case RedactContact = 'contact.redact';
}
