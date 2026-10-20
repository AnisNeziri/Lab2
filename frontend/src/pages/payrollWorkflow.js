// Display existing lifecycle states; this never authorizes a transition.
export const payrollStage = status => ({ draft:0, needs_review:0, calculated:1, approved:3, posted:4, paid:5, closed:6 })[status] ?? -1
export const payrollNext = {
  draft:['Calculate payroll','Llogarit pagat','Review approved employment and workforce records, then calculate this period.','Shqyrto punësimin dhe regjistrimet e miratuara, pastaj llogarit periudhën.'],
  needs_review:['Correct source records','Korrigjo regjistrimet burimore','Resolve the notices below in the employee profile, then calculate again.','Zgjidh njoftimet më poshtë te profili i punonjësit, pastaj rillogarit.'],
  calculated:['Review, then approve','Shqyrto, pastaj mirato','Check employee totals and notices before approving. Posting becomes available after approval.','Kontrollo totalet dhe njoftimet para miratimit. Postimi lejohet pas miratimit.'],
  approved:['Ready to post','Gati për postim','Approval is recorded. Posting creates the payroll journal and payable obligations.','Miratimi është regjistruar. Postimi krijon ditarin dhe detyrimet e pagave.'],
  posted:['Settle payroll obligations','Shlyej detyrimet e pagave','Record authorized employee payments and statutory settlements below. Partial payments remain visible.','Regjistro pagesat e autorizuara dhe detyrimet më poshtë. Pagesat e pjesshme mbeten të dukshme.'],
  paid:['Review and close','Shqyrto dhe mbyll','Employee salaries are paid. Check remaining statutory obligations and reconciliation before closing.','Pagat janë paguar. Kontrollo detyrimet e tjera dhe rakordimin para mbylljes.'],
  closed:['Payroll complete','Pagat përfunduan','This period is closed. Payslips, payments and the posted journal remain available below.','Periudha është mbyllur. Fletëpagesat, pagesat dhe ditari mbeten të disponueshme.'],
  reversed:['Reversed · history preserved','Anuluar · historia ruhet','Review the linked reversal. Prepare a corrected draft if another calculation is required.','Shqyrto anulimin e lidhur. Përgatit draft korrigjues nëse duhet llogaritje tjetër.'],
}

export const payrollFeedback={
  calculated:['Payroll calculated. Review the employees and notices.','Pagat u llogaritën. Shqyrto punonjësit dhe njoftimet.'],
  approved:['Payroll approved. It is ready for posting.','Pagat u miratuan. Janë gati për postim.'],
  posted:['Payroll posted. Review salary and statutory settlements.','Pagat u postuan. Shqyrto pagesat dhe detyrimet ligjore.'],
  closed:['Payroll closed. History and payslips remain available.','Pagat u mbyllën. Historia dhe fletëpagesat mbeten të disponueshme.'],
}
